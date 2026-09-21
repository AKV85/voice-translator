import Alpine from 'alpinejs';

import recorder from './components/recorder.js';
import benchmarkRecorder from './components/benchmark-recorder.js';

window.Alpine = Alpine;

Alpine.data('recorder', recorder);
Alpine.data('benchmarkRecorder', benchmarkRecorder);

Alpine.start();