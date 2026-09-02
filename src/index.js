/**
 * `window.wp.element` is an object provided by WordPress that contains React-related utilities for creating and managing elements
 * in the context of WordPress blocks.
 *
 * ### Key Points:
 * - **Contains React Functions**: It provides access to React functions such as `createElement` for creating React elements.
 * - **No Build Tools Required**: This allows developers to use React's functionality directly in WordPress without needing additional build tools or compilers.
 *
 */

// Modern WC exposes gateway data via getPaymentMethodData("apurata"), not getSetting("apurata_data").
const settings =
  typeof window.wc.wcSettings.getPaymentMethodData === "function"
    ? window.wc.wcSettings.getPaymentMethodData("apurata", {}) || {}
    : window.wc.wcSettings.getSetting("apurata_data", {});
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

const Label = (props) => {
  const PaymentMethodLabel =
    props && props.components && props.components.PaymentMethodLabel;
  const titleNode = PaymentMethodLabel
    ? window.wp.element.createElement(PaymentMethodLabel, { text: label })
    : label;
  return window.wp.element.createElement(
    window.wp.element.Fragment,
    null,
    titleNode,
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

// Same rules as classic is_available / should_hide_apurata_gateway (HTTP, PEN, min/max).
const canMakePayment = (args) => {
  const cartTotals = (args && args.cartTotals) || {};

  if (!settings.allowHttp && window.location && window.location.protocol === "http:") {
    return false;
  }

  if (
    settings.requiredCurrency &&
    cartTotals.currency_code &&
    cartTotals.currency_code !== settings.requiredCurrency
  ) {
    return false;
  }

  const totalMinor = parseInt(cartTotals.total_price || "0", 10);
  if (totalMinor > 0) {
    const total =
      totalMinor / Math.pow(10, parseInt(cartTotals.currency_minor_unit || "2", 10));
    if (typeof settings.minAmount === "number" && total < settings.minAmount) {
      return false;
    }
    if (typeof settings.maxAmount === "number" && total > settings.maxAmount) {
      return false;
    }
  }

  return true;
};

const Block_Gateway = {
  name: "apurata",
  label: Object(window.wp.element.createElement)(Label, null),
  content: Object(window.wp.element.createElement)(Content, null),
  edit: Object(window.wp.element.createElement)(Content, null),
  canMakePayment: canMakePayment,
  ariaLabel: label,
  supports: {
    features: settings.supports || ["products"],
  },
};

window.wc.wcBlocksRegistry.registerPaymentMethod(Block_Gateway);
