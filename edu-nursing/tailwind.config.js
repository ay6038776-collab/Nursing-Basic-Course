/*
  Tailwind CSS configuration for Edu Nursing platform.
  Includes dark‑mode class strategy and Arabic RTL support via the `dir` attribute.
*/
module.exports = {
  content: [
    "./apps/web/**/*.{js,ts,jsx,tsx}",
    "./packages/ui/**/*.{js,ts,jsx,tsx}"
  ],
  darkMode: "class", // or 'media' for OS preference
  theme: {
    extend: {
      colors: {
        primary: "#006d5b", // Medical Teal
        secondary: "#0a1f44", // Deep Navy
        accent: "#2ecc71", // Emerald Green
      },
    },
  },
  plugins: [],
};
