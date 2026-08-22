/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    "./*.php",
    "./includes/**/*.php",
    "./admin/**/*.php",
    "./client/**/*.html",
    "./frontier-asr-v10/**/*.php",
    "./dist/public/**/*.html",
  ],
  theme: {
    extend: {
      colors: {
        primary: "#5271FD",
        secondary: "#0d1b3e",
        accent: "#3ECF8E",
        "blue-mogul-primary": "#5271FD",
        "blue-mogul-secondary": "#0d1b3e",
        "blue-mogul-accent": "#3ECF8E",
      },
      fontFamily: {
        sans: ["Inter", "sans-serif"],
      },
    },
  },
  plugins: [],
  safelist: [],
};