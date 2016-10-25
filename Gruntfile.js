/*jshint node:true */
module.exports = function ( grunt ) {
	var conf = grunt.file.readJSON( 'extension.json' );
	grunt.loadNpmTasks( 'grunt-contrib-jshint' );
	grunt.loadNpmTasks( 'grunt-jsonlint' );
	grunt.loadNpmTasks( 'grunt-banana-checker' );
	grunt.loadNpmTasks( 'grunt-jscs' );
	grunt.loadNpmTasks( 'grunt-contrib-csslint' );

	grunt.initConfig( {
		jshint: {
			options: {
				jshintrc: true
			},
			all: [
				'*.js',
				'modules/**/*.js',
				'tests/**/*.js'
			]
		},
		jscs: {
			src: '<%= jshint.all %>'
		},
		banana: conf.MessagesDirs,
		jsonlint: {
			all: [
				'*.json',
				'**/*.json',
				'!node_modules/**'
			]
		},
		csslint: {
			all: 'modules/**/*.css'
		}
	} );

	grunt.registerTask(
		'test',
		[
			'jshint',
			'jscs',
			'jsonlint',
			'banana',
			'csslint'
		]
	);
	grunt.registerTask( 'default', 'test' );
};
