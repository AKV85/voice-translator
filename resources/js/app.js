import Alpine from 'alpinejs';

import recorder from './components/recorder.js';
import benchmarkRecorder from './components/benchmark-recorder.js';
import liveRecorder from './components/live-recorder.js';

window.Alpine = Alpine;

Alpine.data('recorder', recorder);
Alpine.data('benchmarkRecorder', benchmarkRecorder);
Alpine.data('liveRecorder', liveRecorder);

Alpine.start();