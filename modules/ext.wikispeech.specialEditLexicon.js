var Previewer, $content, $transcription, $language, api, $previewPlayer,
	previewer;

Previewer = require( './ext.wikispeech.transcriptionPreviewer.js' );
$content = $( '#mw-content-text' );
$language = $content.find( '#ext-wikispeech-language select' );
$transcription = $content.find( '#ext-wikispeech-transcription input' );
api = new mw.Api();
$previewPlayer = $( '<audio>' ).insertAfter( $transcription );
previewer = new Previewer( $language, $transcription, api, $previewPlayer );

$content.find( '#ext-wikispeech-preview-button' ).on(
	'click',
	previewer.play.bind( previewer )
);
