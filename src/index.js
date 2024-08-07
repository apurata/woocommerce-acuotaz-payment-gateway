/**
 * `window.wp.element` is an object provided by WordPress that contains React-related utilities for creating and managing elements
 * in the context of WordPress blocks.
 *
 * ### Key Points:
 * - **Contains React Functions**: It provides access to React functions such as `createElement` for creating React elements.
 * - **No Build Tools Required**: This allows developers to use React's functionality directly in WordPress without needing additional build tools or compilers.
 *
 */

// const PLUGIN_ID + _data
const settings = window.wc.wcSettings.getSetting("apurata_data", {});
const label =
  window.wp.htmlEntities.decodeEntities(settings.title) ||
  window.wp.i18n.__("aCuotaz", "apurata");

const Icon = () => {
  if (!settings.icon) return null;
  return window.wp.element.createElement("img", {
    src: settings.icon,
    style: { marginLeft: "auto" },
    alt: "aCuotaz Icon",
  });
};

const Label = () => {
  return window.wp.element.createElement(
    window.wp.element.Fragment,
    null,
    label,
    window.wp.element.createElement(Icon)
  );
};

const executeScript = () => {
  const r = new XMLHttpRequest();
  r.open(
    "GET",
    `https://apurata.com/pos/${settings.clientId}/info-steps`,
    true
  );
  r.onreadystatechange = function () {
    if (r.readyState !== 4 || r.status !== 200) return;
    const elem = document.getElementById("apurata-pos-steps");
    if (elem) {
      elem.innerHTML = r.responseText;
    }
  };
  r.send();
};

const Content = () => {
  window.wp.element.useEffect(() => {
    executeScript();
  }, []);
  return window.wp.element.createElement("div", { id: "apurata-pos-steps" });
};

const Block_Gateway = {
  name: "apurata",
  label: Object(window.wp.element.createElement)(Label, null),
  content: Object(window.wp.element.createElement)(Content, null),
  edit: Object(window.wp.element.createElement)(Content, null),
  canMakePayment: () => settings.canMakePayment,
  ariaLabel: label,
  supports: {
    features: settings.supports,
  },
};

window.wc.wcBlocksRegistry.registerPaymentMethod(Block_Gateway);
