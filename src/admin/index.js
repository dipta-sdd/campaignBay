import App from "./App";
import "./styles/mini-tailwind.css";
import "./styles/index.scss";
import domReady from '@wordpress/dom-ready';
import { createRoot } from '@wordpress/element';


domReady(() => {
	const root = createRoot(
		document.getElementById('campaignbay')
	);
	root.render(<App />);
});


