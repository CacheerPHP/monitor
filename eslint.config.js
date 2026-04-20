import js from "@eslint/js";

export default [
  js.configs.recommended,
  {
    files: ["public/assets/js/**/*.js"],
    languageOptions: {
      ecmaVersion: "latest",
      sourceType: "module",
      globals: {
        window: "readonly",
        document: "readonly",
        localStorage: "readonly",
        navigator: "readonly",
        setTimeout: "readonly",
        setInterval: "readonly",
        clearInterval: "readonly",
        fetch: "readonly",
        prompt: "readonly",
        alert: "readonly",
        confirm: "readonly",
        requestAnimationFrame: "readonly",
        EventSource: "readonly",
        Chart: "readonly",
        MutationObserver: "readonly",
        IntersectionObserver: "readonly",
        URLSearchParams: "readonly",
        console: "readonly",
        tailwind: "readonly",
      },
    },
    rules: {
      curly: ["error", "all"],
      "no-unused-vars": ["warn", { argsIgnorePattern: "^_", caughtErrorsIgnorePattern: "^_" }],
      "no-empty": ["error", { allowEmptyCatch: true }],
    },
  },
];
