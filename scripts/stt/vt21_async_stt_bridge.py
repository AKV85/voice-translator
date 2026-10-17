#!/usr/bin/env python3
"""VT-21 local asynchronous Google Speech V2 bridge (prototype).

Wire protocol: one TCP client = one recognition session, JSON-lines UTF-8.
Client -> server: {"type":"start","language":"ru-RU","sample_rate":24000}
                 {"type":"audio","data":"<base64 PCM16 mono>"}
                 {"type":"stop"} / {"type":"cancel"}
Server -> client: {"type":"ready"}, {"type":"interim","text":"..."},
                  {"type":"final","text":"...","segment":1},
                  {"type":"done"}, {"type":"error","message":"..."}

Local-only developer prototype, not authenticated. Never expose the port publicly.
"""
import argparse
import asyncio
import base64
import binascii
import json
import os
import time
import wave
from google.api_core.client_options import ClientOptions
from google.cloud import speech_v2

MAX_LINE = 25000
MAX_AUDIO = 15360
QUEUE_SIZE = 40


def encode(message):
    return (json.dumps(message, ensure_ascii=False, separators=(',', ':')) + '\n').encode()


async def send(writer, message):
    writer.write(encode(message))
    await writer.drain()


async def handle(reader, writer, project, location, model):
    audio_queue = asyncio.Queue(maxsize=QUEUE_SIZE)
    task = None
    client = None
    terminated = False
    try:
        line = await asyncio.wait_for(reader.readline(), 15)
        if len(line) > MAX_LINE:
            raise ValueError('Control message too long')
        command = json.loads(line)
        if command.get('type') != 'start':
            raise ValueError('First message must be start')
        language = command.get('language')
        sample_rate = command.get('sample_rate')
        if language not in ('ru-RU', 'en-US') or type(sample_rate) is not int or not 8000 <= sample_rate <= 48000:
            raise ValueError('Unsupported language or sample_rate')

        client = speech_v2.SpeechAsyncClient(client_options=ClientOptions(
            api_endpoint=f'{location}-speech.googleapis.com' if location != 'global' else 'speech.googleapis.com'
        ))
        config = speech_v2.RecognitionConfig(
            explicit_decoding_config=speech_v2.ExplicitDecodingConfig(
                encoding=speech_v2.ExplicitDecodingConfig.AudioEncoding.LINEAR16,
                sample_rate_hertz=sample_rate,
                audio_channel_count=1,
            ),
            language_codes=[language], model=model,
        )
        stream_config = speech_v2.StreamingRecognitionConfig(
            config=config,
            streaming_features=speech_v2.StreamingRecognitionFeatures(interim_results=True),
        )
        recognizer = f'projects/{project}/locations/{location}/recognizers/_'

        async def requests():
            yield speech_v2.StreamingRecognizeRequest(recognizer=recognizer, streaming_config=stream_config)
            while True:
                chunk = await audio_queue.get()
                if chunk is None:
                    return
                yield speech_v2.StreamingRecognizeRequest(audio=chunk)

        async def receive():
            segment = 0
            responses = await client.streaming_recognize(requests=requests(), timeout=300)
            async for response in responses:
                for result in response.results:
                    if not result.alternatives:
                        continue
                    value = result.alternatives[0].transcript.strip()
                    if not value:
                        continue
                    if result.is_final:
                        segment += 1
                        await send(writer, {'type': 'final', 'segment': segment, 'text': value})
                    else:
                        await send(writer, {'type': 'interim', 'text': value})
            await send(writer, {'type': 'done'})

        task = asyncio.create_task(receive())
        await send(writer, {'type': 'ready'})
        while not task.done():
            line = await asyncio.wait_for(reader.readline(), timeout=180)
            if not line:
                break
            if len(line) > MAX_LINE:
                raise ValueError('Message too long')
            command = json.loads(line)
            kind = command.get('type')
            if kind == 'audio':
                try:
                    data = base64.b64decode(command['data'], validate=True)
                except (KeyError, TypeError, binascii.Error):
                    raise ValueError('Invalid audio base64')
                if not data or len(data) > MAX_AUDIO or len(data) % 2:
                    raise ValueError('Audio chunk must be nonempty PCM16 <= 15360 bytes')
                # Bound buffered audio: never queue indefinitely if Google stalls.
                await asyncio.wait_for(audio_queue.put(data), timeout=5)
            elif kind == 'stop':
                await audio_queue.put(None)
                terminated = True
                await asyncio.wait_for(task, timeout=30)
                return
            elif kind == 'cancel':
                task.cancel()
                await send(writer, {'type': 'cancelled'})
                return
            else:
                raise ValueError('Unknown command')
        if task.done():
            await task
    except (Exception, asyncio.CancelledError) as exc:
        try:
            await send(writer, {'type': 'error', 'message': str(exc)[:350]})
        except Exception:
            pass
    finally:
        if task is not None and not task.done():
            task.cancel()
            await asyncio.gather(task, return_exceptions=True)
        if client is not None:
            await client.transport.close()
        writer.close()
        try:
            await writer.wait_closed()
        except Exception:
            pass


async def serve(args):
    if not args.project:
        raise SystemExit('Missing --project or GOOGLE_CLOUD_PROJECT')
    if args.host not in ('127.0.0.1', '::1'):
        raise SystemExit('Prototype only listens on loopback')
    server = await asyncio.start_server(
        lambda r, w: handle(r, w, args.project, args.location, args.model),
        args.host, args.port, limit=MAX_LINE + 1,
    )
    print(f'VT-21 STT bridge listening at {args.host}:{args.port} ({args.location}, {args.model})', flush=True)
    async with server:
        await server.serve_forever()


async def probe(args):
    with wave.open(args.wav, 'rb') as wav:
        rate = wav.getframerate()
        if wav.getnchannels() != 1 or wav.getsampwidth() != 2 or wav.getcomptype() != 'NONE':
            raise SystemExit('WAV must be uncompressed PCM16 mono')
        audio = wav.readframes(wav.getnframes())
    reader, writer = await asyncio.open_connection(args.host, args.port)
    started = time.perf_counter()
    await send(writer, {'type': 'start', 'language': args.language, 'sample_rate': rate})
    saw_final = False
    audio_ended = False

    async def receive():
        nonlocal saw_final
        while line := await reader.readline():
            event = json.loads(line)
            elapsed = time.perf_counter() - started
            print(f'[{elapsed:7.3f}s] {event}', flush=True)
            if event['type'] == 'final' and not audio_ended:
                saw_final = True
            if event['type'] in ('done', 'error', 'cancelled'):
                return

    read_task = asyncio.create_task(receive())
    chunk_size = min(int(rate * args.chunk_ms / 1000) * 2, MAX_AUDIO)
    if chunk_size < 2:
        raise SystemExit('Chunk size too small')
    for offset in range(0, len(audio), chunk_size):
        chunk = audio[offset:offset + chunk_size]
        await send(writer, {'type': 'audio', 'data': base64.b64encode(chunk).decode('ascii')})
        await asyncio.sleep(len(chunk) / (rate * 2))
    audio_ended = True
    print(f'[{time.perf_counter()-started:7.3f}s] AUDIO FINISHED', flush=True)
    await asyncio.sleep(args.keep_open)
    await send(writer, {'type': 'stop'})
    await asyncio.wait_for(read_task, timeout=40)
    writer.close()
    await writer.wait_closed()
    print(f'RESULT: final_before_audio_finished={saw_final}', flush=True)


def main():
    parser = argparse.ArgumentParser(description='VT-21 asynchronous STT bridge + WAV test client')
    parser.add_argument('mode', choices=('serve', 'probe'))
    parser.add_argument('wav', nargs='?')
    parser.add_argument('--project', default=os.getenv('GOOGLE_CLOUD_PROJECT'))
    parser.add_argument('--host', default='127.0.0.1')
    parser.add_argument('--port', type=int, default=8767)
    parser.add_argument('--location', default='eu')
    parser.add_argument('--model', default='chirp_3')
    parser.add_argument('--language', default='ru-RU')
    parser.add_argument('--chunk-ms', type=int, default=100)
    parser.add_argument('--keep-open', type=float, default=2)
    args = parser.parse_args()
    if args.mode == 'probe' and not args.wav:
        parser.error('probe requires WAV path')
    try:
        asyncio.run(serve(args) if args.mode == 'serve' else probe(args))
    except KeyboardInterrupt:
        pass


if __name__ == '__main__':
    main()
