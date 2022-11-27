const navigation = document.getElementById('navigation');
const editor = document.getElementById('editor');
const mainContent = document.getElementById('main-content');
const handle = document.getElementById('handle');
const lineNumbers = document.getElementById('line-numbers');

document.addEventListener("DOMContentLoaded", function () {
	handle.addEventListener('mousedown', setupEditorResizer, false);
	window.addEventListener('resize', updateLineNumbers);
	updateLineNumbers();
});

let StartX, contentStartWidth;

function setupEditorResizer(event) {
	StartX = event.clientX;
	contentStartWidth = parseInt(window.getComputedStyle(navigation).width, 10);
	document.documentElement.addEventListener('mousemove', dragEditorResizerCallback, false);
	document.documentElement.addEventListener('mouseup', destroyEditorResizerCallback, false);
}

function dragEditorResizerCallback(event) {
	// Don't allow going under 280px or over 45% viewport width
	if (event.clientX > 280 && event.clientX < window.innerWidth * 0.45) {
		navigation.style.flexBasis = event.clientX + 'px';
		updateLineNumbers();
	}
}

function destroyEditorResizerCallback() {
	document.documentElement.removeEventListener('mousemove', dragEditorResizerCallback, false);
	document.documentElement.removeEventListener('mouseup', destroyEditorResizerCallback, false);
}

function updateLineNumbers() {
	// Remove all line numbers
	lineNumbers.querySelectorAll('i').forEach(n => n.remove());

	// Set up the line numbers container height
	lineNumbers.style.height = mainContent.scrollHeight + 'px';

	// Calculate how many line numbers we want and append them to the DOM
	const totalNumbers = (mainContent.scrollHeight) / 35;
	Array.from({length: totalNumbers}, () => {
		lineNumbers.appendChild(document.createElement("i"));
	});
}
