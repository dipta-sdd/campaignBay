const path = require("path");
const defaultConfig = require("@wordpress/scripts/config/webpack.config");

const isLegacy = process.env.BUILD_TYPE === "legacy";

module.exports = {
  ...defaultConfig,
  entry: {
    admin: "./src/admin/index.js",
    public: "./src/public/index.js",
  },
  output: {
    ...defaultConfig.output,
    path: path.resolve(__dirname, `build/${isLegacy ? "legacy" : "modern"}`),
    filename: "[name].js",
  },
  externals: isLegacy
    ? {} // ✅ Bundle everything for legacy
    : {
        react: "React",
        "react-dom": "ReactDOM",
        "@wordpress/element": "wp.element",
        "@wordpress/i18n": "wp.i18n",
        "@wordpress/date": "wp.date",
        "@wordpress/icons": "wp.icons",
        "@wordpress/react-i18n": "wp.reactI18n",
        "@wordpress/dataviews": "wp.dataViews",
      },
};
