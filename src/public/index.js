import App from "./App";
import "./styles/main.css";
import domReady from '@wordpress/dom-ready';
import { createRoot } from '@wordpress/element';

domReady( () => {
	const root = createRoot(
		document.getElementById( 'campaignbay' )
	);
	root.render( <App /> );
} );