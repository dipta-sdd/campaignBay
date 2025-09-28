import App from "./App";
import "./styles/index.scss";
import domReady from "@wordpress/dom-ready";
import React from "react";
import { createRoot } from "react-dom/client";
console.log("React version:", React.version);

domReady(() => {
  const el = document.getElementById("campaignbay");
  if (el) {
    const root = createRoot(el);
    root.render(<App />);
  }
});
