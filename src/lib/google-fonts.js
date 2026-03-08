/**
 * Curated Google Fonts list for the Theme Customizer.
 * ~35 popular fonts organized by category.
 */

export const googleFonts = [
  // Sans-serif
  { name: 'Inter', category: 'Sans Serif', weights: '400;500;600;700' },
  { name: 'Roboto', category: 'Sans Serif', weights: '400;500;700' },
  { name: 'Open Sans', category: 'Sans Serif', weights: '400;600;700' },
  { name: 'Lato', category: 'Sans Serif', weights: '400;700' },
  { name: 'Montserrat', category: 'Sans Serif', weights: '400;500;600;700' },
  { name: 'Poppins', category: 'Sans Serif', weights: '400;500;600;700' },
  { name: 'Nunito', category: 'Sans Serif', weights: '400;600;700' },
  { name: 'Source Sans 3', category: 'Sans Serif', weights: '400;600;700' },
  { name: 'DM Sans', category: 'Sans Serif', weights: '400;500;700' },
  { name: 'Work Sans', category: 'Sans Serif', weights: '400;500;600;700' },
  { name: 'Manrope', category: 'Sans Serif', weights: '400;500;600;700;800' },
  { name: 'Plus Jakarta Sans', category: 'Sans Serif', weights: '400;500;600;700' },
  { name: 'Outfit', category: 'Sans Serif', weights: '400;500;600;700' },
  { name: 'Sora', category: 'Sans Serif', weights: '400;500;600;700' },

  // Serif
  { name: 'Playfair Display', category: 'Serif', weights: '400;500;600;700' },
  { name: 'Merriweather', category: 'Serif', weights: '400;700' },
  { name: 'Lora', category: 'Serif', weights: '400;500;600;700' },
  { name: 'PT Serif', category: 'Serif', weights: '400;700' },
  { name: 'Crimson Text', category: 'Serif', weights: '400;600;700' },
  { name: 'Cormorant Garamond', category: 'Serif', weights: '400;500;600;700' },
  { name: 'Libre Baskerville', category: 'Serif', weights: '400;700' },
  { name: 'DM Serif Display', category: 'Serif', weights: '400' },
  { name: 'Fraunces', category: 'Serif', weights: '400;500;600;700' },

  // Display
  { name: 'Bebas Neue', category: 'Display', weights: '400' },
  { name: 'Oswald', category: 'Display', weights: '400;500;600;700' },
  { name: 'Space Grotesk', category: 'Display', weights: '400;500;600;700' },
  { name: 'Raleway', category: 'Display', weights: '400;500;600;700' },

  // Monospace
  { name: 'JetBrains Mono', category: 'Monospace', weights: '400;500;700' },
  { name: 'Fira Code', category: 'Monospace', weights: '400;500;700' },
];

/** Group fonts by category for <optgroup> rendering. */
export function fontsByCategory() {
  const groups = {};
  for (const font of googleFonts) {
    if (!groups[font.category]) groups[font.category] = [];
    groups[font.category].push(font);
  }
  return groups;
}

/** Build a Google Fonts CSS URL for a font name. */
export function googleFontsUrl(fontName, weights = '400;500;600;700') {
  if (!fontName || fontName === 'System Default') return null;
  const family = fontName.replace(/ /g, '+');
  return `https://fonts.googleapis.com/css2?family=${family}:wght@${weights}&display=swap`;
}

/** Build a lightweight preview URL (only a few glyphs). */
export function googleFontsPreviewUrl(fontName) {
  if (!fontName || fontName === 'System Default') return null;
  const family = fontName.replace(/ /g, '+');
  return `https://fonts.googleapis.com/css2?family=${family}:wght@400;700&display=swap&text=AaBbCc+123`;
}
