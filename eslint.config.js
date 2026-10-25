import js from "@eslint/js";
import globals from "globals";

export default [
    js.configs.recommended,
    {
        files: ["resources/js/components/conversation-pcm-worklet.js"],
        languageOptions: {
            globals: {
                AudioWorkletProcessor: "readonly",
                sampleRate: "readonly",
                registerProcessor: "readonly",
            },
        },
    },

    {
        files: ["resources/js/**/*.js"],

        languageOptions: {
            ecmaVersion: "latest",
            sourceType: "module",

            globals: {
                ...globals.browser,
            },
        },

        rules: {
            "no-unused-vars": [
                "error",
                {
                    argsIgnorePattern: "^_",
                },
            ],
        },
    },
];
