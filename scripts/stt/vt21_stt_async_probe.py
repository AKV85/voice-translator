#!/usr/bin/env python3
"""Isolated Speech-to-Text V2 bidi-stream test. No Laravel changes.

Requirements: pip install google-cloud-speech
Credentials: Application Default Credentials (GOOGLE_APPLICATION_CREDENTIALS or gcloud auth application-default login)
Audio: uncompressed mono 16-bit PCM WAV, containing two sentences and a short pause.
"""
import argparse
import asyncio
import os
import time
import wave

from google.cloud import speech_v2


def arguments():
    p = argparse.ArgumentParser(description='Test Google STT V2 responses before audio ends')
    p.add_argument('wav', help='Path to PCM16 mono WAV with two sentences')
    p.add_argument('--project', default=os.getenv('GOOGLE_CLOUD_PROJECT'), required=False)
    p.add_argument('--location', default='global')
    p.add_argument('--language', default='ru-RU')
    p.add_argument('--model', default='chirp_3')
    p.add_argument('--chunk-ms', type=int, default=100)
    p.add_argument('--keep-open', type=float, default=2.0,
                   help='Seconds to keep stream open after file ends (no more audio)')
    return p.parse_args()


async def main():
    args = arguments()
    if not args.project:
        raise SystemExit('Missing --project or GOOGLE_CLOUD_PROJECT')
    if not (20 <= args.chunk_ms <= 500):
        raise SystemExit('--chunk-ms must be between 20 and 500')
    if not (0 <= args.keep_open <= 15):
        raise SystemExit('--keep-open must be between 0 and 15')

    with wave.open(args.wav, 'rb') as wav:
        channels, sample_width, sample_rate = wav.getnchannels(), wav.getsampwidth(), wav.getframerate()
        if channels != 1 or sample_width != 2:
            raise SystemExit('WAV must be mono, PCM16 (16-bit). Convert before running.')
        if sample_rate < 8000 or sample_rate > 48000:
            raise SystemExit(f'Unexpected WAV sample rate: {sample_rate}')
        audio = wav.readframes(wav.getnframes())

    frame_bytes = sample_width * channels
    chunk_frames = int(sample_rate * args.chunk_ms / 1000)
    chunk_bytes = chunk_frames * frame_bytes
    if chunk_bytes > 15360:
        raise SystemExit('Chunk exceeds Google Speech streaming request limit')
    duration_s = len(audio) / (sample_rate * frame_bytes)

    config = speech_v2.RecognitionConfig(
        explicit_decoding_config=speech_v2.ExplicitDecodingConfig(
            encoding=speech_v2.ExplicitDecodingConfig.AudioEncoding.LINEAR16,
            sample_rate_hertz=sample_rate,
            audio_channel_count=1,
        ),
        language_codes=[args.language],
        model=args.model,
    )
    stream_config = speech_v2.StreamingRecognitionConfig(
        config=config,
        streaming_features=speech_v2.StreamingRecognitionFeatures(
            interim_results=True,
        ),
    )
    recognizer = f'projects/{args.project}/locations/{args.location}/recognizers/_'
    client_opts = None
    if args.location != 'global':
        from google.api_core.client_options import ClientOptions
        client_opts = ClientOptions(api_endpoint=f'{args.location}-speech.googleapis.com')
    client = speech_v2.SpeechAsyncClient(client_options=client_opts)

    start = time.perf_counter()
    audio_ended = asyncio.Event()
    first_final_before_audio_end = False
    final_count = 0

    async def requests():
        yield speech_v2.StreamingRecognizeRequest(
            recognizer=recognizer,
            streaming_config=stream_config,
        )
        print(f'Audio duration: {duration_s:.2f}s; chunk: {args.chunk_ms}ms', flush=True)
        for offset in range(0, len(audio), chunk_bytes):
            chunk = audio[offset:offset + chunk_bytes]
            yield speech_v2.StreamingRecognizeRequest(audio=chunk)
            await asyncio.sleep(len(chunk) / (sample_rate * frame_bytes))
        audio_ended.set()
        print(f'[{time.perf_counter() - start:7.3f}s] AUDIO FILE FINISHED (stream still open)', flush=True)
        if args.keep_open:
            await asyncio.sleep(args.keep_open)
        print(f'[{time.perf_counter() - start:7.3f}s] REQUEST STREAM CLOSED', flush=True)

    try:
        responses = await client.streaming_recognize(requests=requests(), timeout=max(90, duration_s + args.keep_open + 30))
        async for response in responses:
            t = time.perf_counter() - start
            for result in response.results:
                if not result.alternatives:
                    continue
                kind = 'FINAL' if result.is_final else 'INTERIM'
                print(f'[{t:7.3f}s] {kind:<7} {result.alternatives[0].transcript}', flush=True)
                if result.is_final:
                    final_count += 1
                    if not audio_ended.is_set():
                        first_final_before_audio_end = True
        print(f'RESULT: final_segments={final_count}, final_before_audio_finished={first_final_before_audio_end}')
    finally:
        # Close the gRPC channel after the response iterator is finished.
        await client.transport.close()


if __name__ == '__main__':
    asyncio.run(main())
