import Alpine from 'alpinejs';
import recorder from './components/recorder.js';

window.Alpine = Alpine;

Alpine.data('recorder', recorder);

Alpine.start();