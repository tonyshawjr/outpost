export const COLOR_STEPS = [50, 100, 200, 300, 400, 500, 600, 700, 800, 900];
export const SCALE_STEPS = ['xs', 'sm', 'md', 'lg', 'xl', '2xl', '3xl'];
const SCALE_EXP = { xs: -2, sm: -1, md: 0, lg: 1, xl: 2, '2xl': 3, '3xl': 4 };
const TOKEN_NAME_RE = /^[a-z][a-z0-9-]*$/;

export function defaultTokens() {
  return {
    colors: [
      { name: 'brand', value: '#7C3AED', utilities: true },
      { name: 'ink', value: '#101114', utilities: true },
    ],
    type: { baseMin: 16, baseMax: 18, ratio: 1.2, minVw: 360, maxVw: 1280 },
    spacing: { baseMin: 14, baseMax: 18, ratio: 1.5, minVw: 360, maxVw: 1280 },
  };
}

export function colorNameValid(name) {
  return TOKEN_NAME_RE.test(name);
}

function hexToRgb(hex) {
  let h = hex.trim().replace('#', '');
  if (h.length === 3) h = h.split('').map((c) => c + c).join('');
  if (h.length !== 6 || /[^0-9a-fA-F]/.test(h)) return null;
  return [parseInt(h.slice(0, 2), 16), parseInt(h.slice(2, 4), 16), parseInt(h.slice(4, 6), 16)];
}

function rgbToHsl(r, g, b) {
  r /= 255; g /= 255; b /= 255;
  const max = Math.max(r, g, b), min = Math.min(r, g, b);
  let h = 0, s = 0;
  const l = (max + min) / 2;
  if (max !== min) {
    const d = max - min;
    s = l > 0.5 ? d / (2 - max - min) : d / (max + min);
    if (max === r) h = (g - b) / d + (g < b ? 6 : 0);
    else if (max === g) h = (b - r) / d + 2;
    else h = (r - g) / d + 4;
    h /= 6;
  }
  return [h * 360, s * 100, l * 100];
}

function hslCss(h, s, l) {
  return `hsl(${Math.round(h)} ${Math.round(s)}% ${Math.round(Math.max(0, Math.min(100, l)))}%)`;
}

const STEP_LIGHTNESS = { 50: 97, 100: 93, 200: 85, 300: 74, 400: 62, 500: null, 600: 44, 700: 36, 800: 27, 900: 17 };

export function colorScale(value) {
  const rgb = hexToRgb(value);
  if (!rgb) return null;
  const [h, s, baseL] = rgbToHsl(...rgb);
  const out = {};
  for (const step of COLOR_STEPS) {
    const target = STEP_LIGHTNESS[step];
    out[step] = step === 500 ? hslCss(h, s, baseL) : hslCss(h, s, target);
  }
  return out;
}

function fluid(minPx, maxPx, minVw, maxVw) {
  const minRem = minPx / 16, maxRem = maxPx / 16;
  if (maxPx <= minPx) return `${minRem.toFixed(3)}rem`;
  const slope = (maxPx - minPx) / (maxVw - minVw);
  const interceptRem = (minPx - slope * minVw) / 16;
  const slopeVw = (slope * 100).toFixed(3);
  return `clamp(${minRem.toFixed(3)}rem, ${interceptRem.toFixed(3)}rem + ${slopeVw}vw, ${maxRem.toFixed(3)}rem)`;
}

export function typeScale(opts) {
  const { baseMin, baseMax, ratio, minVw, maxVw } = { ...defaultTokens().type, ...opts };
  const out = {};
  for (const step of SCALE_STEPS) {
    const exp = SCALE_EXP[step];
    out[step] = fluid(baseMin * ratio ** exp, baseMax * ratio ** exp, minVw, maxVw);
  }
  return out;
}

export function spacingScale(opts) {
  const { baseMin, baseMax, ratio, minVw, maxVw } = { ...defaultTokens().spacing, ...opts };
  const out = {};
  for (const step of SCALE_STEPS) {
    const exp = SCALE_EXP[step];
    out[step] = fluid(baseMin * ratio ** exp, baseMax * ratio ** exp, minVw, maxVw);
  }
  return out;
}

export function tokenVarNames(tokens) {
  const colors = [];
  for (const c of tokens.colors || []) {
    if (!colorNameValid(c.name)) continue;
    colors.push(`--color-${c.name}`);
    for (const step of COLOR_STEPS) colors.push(`--color-${c.name}-${step}`);
  }
  const text = SCALE_STEPS.map((s) => `--text-${s}`);
  const space = SCALE_STEPS.map((s) => `--space-${s}`);
  return { colors, text, space };
}

export function tokensToCss(tokens, scope = '.oc-canvas') {
  let vars = '';
  let utils = '';
  for (const c of tokens.colors || []) {
    if (!colorNameValid(c.name)) continue;
    const scale = colorScale(c.value);
    if (!scale) continue;
    vars += `--color-${c.name}:${scale[500]};`;
    for (const step of COLOR_STEPS) vars += `--color-${c.name}-${step}:${scale[step]};`;
    if (c.utilities) {
      utils += `${scope} .text-${c.name}{color:var(--color-${c.name});}\n`;
      utils += `${scope} .bg-${c.name}{background-color:var(--color-${c.name});}\n`;
      utils += `${scope} .border-${c.name}{border-color:var(--color-${c.name});}\n`;
    }
  }
  const type = typeScale(tokens.type || {});
  for (const step of SCALE_STEPS) vars += `--text-${step}:${type[step]};`;
  const space = spacingScale(tokens.spacing || {});
  for (const step of SCALE_STEPS) vars += `--space-${step}:${space[step]};`;
  return `${scope}{${vars}}\n${utils}`;
}
