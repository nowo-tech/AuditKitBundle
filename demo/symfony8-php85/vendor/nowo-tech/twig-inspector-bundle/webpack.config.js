const path = require('path');
const MiniCssExtractPlugin = require('mini-css-extract-plugin');

module.exports = {
  entry: {
    index: './src/Resources/assets/src/index.ts',
  },
  output: {
    path: path.resolve(__dirname, 'src/Resources/assets/dist'),
    filename: '[name].min.js',
    clean: true,
  },
  resolve: {
    extensions: ['.ts', '.js'],
  },
  module: {
    rules: [
      {
        test: /\.ts$/,
        use: 'ts-loader',
        exclude: /node_modules/,
      },
      {
        test: /\.scss$/,
        use: [
          MiniCssExtractPlugin.loader,
          'css-loader',
          'sass-loader',
        ],
      },
    ],
  },
  plugins: [
    new MiniCssExtractPlugin({
      filename: '[name].min.css',
    }),
  ],
  optimization: {
    minimize: true,
  },
};

