import Alpine from "alpinejs";

import recorder from "./components/recorder.js";
import benchmarkRecorder from "./components/benchmark-recorder.js";
import liveRecorder from "./components/live-recorder.js";
import compareRecorder from "./components/compare-recorder.js";
import liveHistory from "./components/live-history.js";
import publicDemoRecorder from "./components/public-demo-recorder.js";

window.Alpine = Alpine;

Alpine.data("recorder", recorder);
Alpine.data("benchmarkRecorder", benchmarkRecorder);
Alpine.data("liveRecorder", liveRecorder);
Alpine.data("compareRecorder", compareRecorder);
Alpine.data("liveHistory", liveHistory);
Alpine.data("publicDemoRecorder", publicDemoRecorder);

Alpine.start();
