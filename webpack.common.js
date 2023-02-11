/* eslint-disable @typescript-eslint/no-var-requires */
const path = require('path');
const webpack = require("webpack");

module.exports = {
	entry: {
		
		settings: [
			path.join(__dirname, 'src', 'settings.ts'),
		],
	},
	output: {
		path: path.resolve(__dirname, './js'),
		publicPath: '/js/',
		filename: '[name].js',
		chunkFilename: 'chunks/[name]-[contenthash].js',
	},
	module: {
		rules: [
			{
				test: /\.ts$/,
				use: ['ts-loader'],
			},
			{
				test: /\.css$/,
				use: ['style-loader', 'css-loader'],
			},
			{
				test: /\.scss$/,
				use: ['style-loader', 'css-loader', 'sass-loader'],
			},
			{
				test: /\.(png|jpg|gif|svg)$/,
				loader: 'url-loader',
				options: {
					name: '[name].[ext]?[contenthash]',
					limit: 8192,
				},
			},
		],
	},
	plugins: [
		new webpack.ProvidePlugin({
			$: 'jquery',
			jQuery: 'jquery'
		}),
	],
	resolve: {
		extensions: ['*', '.ts', '.js', '.scss'],
		symlinks: false,
	},
}
