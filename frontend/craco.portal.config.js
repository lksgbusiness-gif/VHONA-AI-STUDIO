const path = require('path');
const baseConfig = require('./craco.config');

const baseWebpack = baseConfig.webpack || {};
const baseConfigure = baseWebpack.configure;

module.exports = {
  ...baseConfig,
  webpack: {
    ...baseWebpack,
    configure: (webpackConfig, context) => {
      const result = typeof baseConfigure === 'function' ? baseConfigure(webpackConfig, context) : webpackConfig;

      const portalEntry = path.resolve(__dirname, 'src/portal/index.js');

      if (context && context.paths) {
        context.paths.appIndexJs = portalEntry;
      }

      result.entry = portalEntry;

      return result;
    },
  },
};
