module.exports = {
	root: true,
	extends: [ 'plugin:@wordpress/eslint-plugin/recommended' ],
	overrides: [
		{
			// Scripts livrés sans build (builder Bricks, barre client) : ES5,
			// navigateur, confirm() réservé aux actions irréversibles (§9).
			files: [ 'assets/**/*.js' ],
			env: { browser: true },
			rules: {
				'no-var': 'off',
				'no-alert': 'off',
				'no-nested-ternary': 'off',
				'@wordpress/no-global-active-element': 'off',
				'@wordpress/no-unused-vars-before-return': 'off',
				'jsdoc/require-param-type': 'off',
			},
		},
		{
			files: [ 'src-js/**/*.js' ],
			rules: {
				// Abandon et suppression de données : confirmation explicite voulue.
				'no-alert': 'off',
			},
		},
	],
};
