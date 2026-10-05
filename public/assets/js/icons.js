// Local, shared icon artwork. Names come from the bundled symbol set.
export function icon(name, className = "") {
  return `<svg class="icon ${className}" viewBox="0 0 24 24" aria-hidden="true"><use href="assets/art/icons.svg#${name}"></use></svg>`;
}
