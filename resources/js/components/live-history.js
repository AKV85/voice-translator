export default (config = {}) => ({
    endpoint: config.endpoint,
    qualityBaseUrl: config.qualityBaseUrl,
    csrfToken: config.csrfToken,

    runs: [],

    filters: {
        direction: "",
        pipeline: "",
        quality: "",
        date_from: "",
        date_to: "",
    },

    qualityIssues: [
        {
            value: "stt_wrong",
            label: "STT wrong",
        },
        {
            value: "translation_wrong",
            label: "Translation wrong",
        },
        {
            value: "meaning_changed",
            label: "Meaning changed",
        },
        {
            value: "name_place_corrupted",
            label: "Name/place corrupted",
        },
        {
            value: "number_time_wrong",
            label: "Number/time wrong",
        },
        {
            value: "other",
            label: "Other",
        },
    ],

    currentPage: 1,
    lastPage: 1,
    total: 0,

    isLoading: false,
    error: null,

    init() {
        this.loadRuns();
    },

    async loadRuns(page = 1) {
        if (this.isLoading) {
            return;
        }

        this.isLoading = true;
        this.error = null;

        try {
            const url = new URL(this.endpoint, window.location.origin);

            Object.entries(this.filters).forEach(([key, value]) => {
                if (value !== "") {
                    url.searchParams.set(key, value);
                }
            });

            url.searchParams.set("page", page);

            const response = await fetch(url.toString(), {
                headers: {
                    Accept: "application/json",
                },
            });

            const data = await response.json();

            if (!response.ok) {
                throw new Error(
                    data.message ?? "Could not load live pipeline history.",
                );
            }

            this.runs = Array.isArray(data.data)
                ? data.data.map((run) => this.prepareRun(run))
                : [];

            this.currentPage = Number(data.current_page ?? 1);

            this.lastPage = Number(data.last_page ?? 1);

            this.total = Number(data.total ?? 0);
        } catch (error) {
            this.error =
                error instanceof Error
                    ? error.message
                    : "Could not load live pipeline history.";
        } finally {
            this.isLoading = false;
        }
    },

    prepareRun(run) {
        return {
            ...run,

            reviewRating: run.quality_rating ?? "",

            reviewIssues: Array.isArray(run.quality_issues)
                ? [...run.quality_issues]
                : [],

            isSavingReview: false,
            reviewSaved: false,
            reviewError: null,
        };
    },

    applyFilters() {
        this.loadRuns(1);
    },

    resetFilters() {
        this.filters = {
            direction: "",
            pipeline: "",
            quality: "",
            date_from: "",
            date_to: "",
        };

        this.loadRuns(1);
    },

    previousPage() {
        if (this.currentPage <= 1 || this.isLoading) {
            return;
        }

        this.loadRuns(this.currentPage - 1);
    },

    nextPage() {
        if (this.currentPage >= this.lastPage || this.isLoading) {
            return;
        }

        this.loadRuns(this.currentPage + 1);
    },

    markReviewDirty(run) {
        run.reviewSaved = false;
        run.reviewError = null;

        if (run.reviewRating !== "wrong") {
            run.reviewIssues = [];
        }
    },

    async saveReview(run) {
        if (run.isSavingReview) {
            return;
        }

        if (!run.reviewRating) {
            run.reviewError = "Choose a quality rating.";

            return;
        }

        run.isSavingReview = true;
        run.reviewSaved = false;
        run.reviewError = null;

        try {
            const response = await fetch(
                `${this.qualityBaseUrl}/${run.id}/quality`,
                {
                    method: "PATCH",

                    headers: {
                        Accept: "application/json",
                        "Content-Type": "application/json",
                        "X-CSRF-TOKEN": this.csrfToken,
                    },

                    body: JSON.stringify({
                        quality_rating: run.reviewRating,

                        quality_issues:
                            run.reviewRating === "wrong"
                                ? run.reviewIssues
                                : [],
                    }),
                },
            );

            const data = await response.json().catch(() => null);

            if (!response.ok) {
                const validationMessage = data?.errors
                    ? Object.values(data.errors).flat()[0]
                    : null;

                throw new Error(
                    validationMessage ??
                        data?.message ??
                        "Could not save quality review.",
                );
            }

            run.quality_rating = data.quality_rating ?? null;

            run.quality_issues = Array.isArray(data.quality_issues)
                ? data.quality_issues
                : null;

            run.reviewed_at = data.reviewed_at ?? null;

            run.reviewRating = run.quality_rating ?? "";

            run.reviewIssues = Array.isArray(run.quality_issues)
                ? [...run.quality_issues]
                : [];

            run.reviewSaved = true;

            if (this.reviewNoLongerMatchesFilter(run)) {
                await this.loadRuns(this.currentPage);
            }
        } catch (error) {
            run.reviewError =
                error instanceof Error
                    ? error.message
                    : "Could not save quality review.";
        } finally {
            run.isSavingReview = false;
        }
    },

    reviewNoLongerMatchesFilter(run) {
        if (this.filters.quality === "") {
            return false;
        }

        if (this.filters.quality === "unreviewed") {
            return run.quality_rating !== null;
        }

        return run.quality_rating !== this.filters.quality;
    },

    profileLabel(profile) {
        const labels = {
            "chirp3-deepl-openai": "Chirp 3 Batch",

            "chirp3-streaming-standard-deepl-openai": "Chirp 3 STANDARD",

            "chirp3-streaming-short-deepl-openai": "Chirp 3 SHORT",

            "flux-deepl-openai": "Deepgram Flux",
        };

        return labels[profile] ?? profile;
    },

    directionLabel(run) {
        return `${run.source_language.toUpperCase()} → ${run.target_language.toUpperCase()}`;
    },

    qualityLabel(run) {
        if (!run.quality_rating) {
            return "Unreviewed";
        }

        return (
            run.quality_rating.charAt(0).toUpperCase() +
            run.quality_rating.slice(1)
        );
    },

    formatDate(value) {
        if (!value) {
            return "—";
        }

        const date = new Date(value);

        if (Number.isNaN(date.getTime())) {
            return value;
        }

        return date.toLocaleString();
    },

    formatMs(value) {
        const number = Number(value);

        if (!Number.isFinite(number)) {
            return "—";
        }

        return `${number.toFixed(2)} ms`;
    },

    metric(run, key) {
        return run.metrics?.[key] ?? null;
    },

    formatIssues(run) {
        if (
            !Array.isArray(run.quality_issues) ||
            run.quality_issues.length === 0
        ) {
            return "—";
        }

        return run.quality_issues.join(", ");
    },

    formatMetrics(run) {
        if (!run.metrics || typeof run.metrics !== "object") {
            return "No metrics";
        }

        return JSON.stringify(run.metrics, null, 2);
    },
});
