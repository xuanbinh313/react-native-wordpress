const colors = require('./colors');
/** @type {import('tailwindcss').Config} */
module.exports = {
  // include all app files so NativeWind can pick up className usage
  content: ['./**/*.{js,jsx,ts,tsx}'],

  presets: [require('nativewind/preset')],
  theme: {
    extend: {
      colors: {
        ...colors,
      },
    },
  },
  plugins: [],
};
