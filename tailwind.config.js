/** @type {import('tailwindcss').Config} */
module.exports = {
  prefix: "campaignbay-",
  content: ["./src/admin/**/*.{js,jsx,ts,tsx}"],
  theme: {
    extend: {
      colors: {
        gray: "#6b7280",
        blue: "#3b82f6",
        red: "#ef4444",
      },
    },
  },
  plugins: [],
};
