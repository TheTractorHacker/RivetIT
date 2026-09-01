/* @ds-bundle: {"format":3,"namespace":"ITFlow_b1b893","components":[{"name":"Alert","sourcePath":"components/core/Alert.jsx"},{"name":"Avatar","sourcePath":"components/core/Avatar.jsx"},{"name":"Badge","sourcePath":"components/core/Badge.jsx"},{"name":"Button","sourcePath":"components/core/Button.jsx"},{"name":"Card","sourcePath":"components/core/Card.jsx"},{"name":"IconButton","sourcePath":"components/core/IconButton.jsx"},{"name":"PriorityDot","sourcePath":"components/data/PriorityDot.jsx"},{"name":"SmallBox","sourcePath":"components/data/SmallBox.jsx"},{"name":"StatBox","sourcePath":"components/data/StatBox.jsx"},{"name":"Input","sourcePath":"components/forms/Input.jsx"},{"name":"Select","sourcePath":"components/forms/Select.jsx"},{"name":"Switch","sourcePath":"components/forms/Switch.jsx"}],"sourceHashes":{"components/core/Alert.jsx":"91cb7f8769b3","components/core/Avatar.jsx":"081bb5e3bed4","components/core/Badge.jsx":"bdc0e5c2f74b","components/core/Button.jsx":"3bd5b1e322c5","components/core/Card.jsx":"37900e36219f","components/core/IconButton.jsx":"a7cf8fbb65c7","components/data/PriorityDot.jsx":"4d2e7a1bee73","components/data/SmallBox.jsx":"b413e35dd6d8","components/data/StatBox.jsx":"ae5507d6cc4f","components/forms/Input.jsx":"4729bcb305a7","components/forms/Select.jsx":"02048d5ca4ee","components/forms/Switch.jsx":"bb314d70725c","ui_kits/itflow/AdminScreens.jsx":"1f0249dde4b5","ui_kits/itflow/Clients.jsx":"3d6529531c3c","ui_kits/itflow/Dashboard.jsx":"15512b56eaec","ui_kits/itflow/Login.jsx":"d69df9bfd1d5","ui_kits/itflow/Shell.jsx":"80be7710e78e","ui_kits/itflow/TicketDetail.jsx":"60b1dd5759fa","ui_kits/itflow/Tickets.jsx":"31f42a81e950","ui_kits/itflow/app.jsx":"7a0536f0eaba","ui_kits/itflow/data.js":"a0ab5c8656be"},"inlinedExternals":[],"unexposedExports":[]} */

(() => {

const __ds_ns = (window.ITFlow_b1b893 = window.ITFlow_b1b893 || {});

const __ds_scope = {};

(__ds_ns.__errors = __ds_ns.__errors || []);

// components/core/Alert.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
const HUES = {
  success: 'var(--success)',
  info: 'var(--info)',
  warning: 'var(--warning)',
  danger: 'var(--danger)',
  accent: 'var(--color-accent)'
};
const ICONS = {
  success: 'check-circle',
  info: 'info-circle',
  warning: 'exclamation-triangle',
  danger: 'exclamation-circle',
  accent: 'bell'
};

/**
 * Inline alert / feedback banner. Soft tinted background with a
 * leading icon — matches ITFlow's toastr-style messaging.
 */
function Alert({
  color = 'info',
  icon,
  title,
  onClose,
  children,
  style,
  ...rest
}) {
  const hue = HUES[color] || HUES.info;
  const ic = icon || ICONS[color];
  return /*#__PURE__*/React.createElement("div", _extends({
    role: "alert",
    style: {
      display: 'flex',
      alignItems: 'flex-start',
      gap: '0.6rem',
      padding: '0.7rem 0.9rem',
      fontFamily: 'var(--font-sans)',
      fontSize: 'var(--text-sm)',
      color: 'var(--color-text)',
      background: `color-mix(in srgb, ${hue} 12%, var(--color-surface))`,
      borderLeft: `4px solid ${hue}`,
      borderRadius: 'var(--input-radius)',
      ...style
    }
  }, rest), ic && /*#__PURE__*/React.createElement("i", {
    className: `fas fa-${ic}`,
    style: {
      color: hue,
      marginTop: '0.15rem'
    },
    "aria-hidden": "true"
  }), /*#__PURE__*/React.createElement("div", {
    style: {
      flex: 1
    }
  }, title && /*#__PURE__*/React.createElement("div", {
    style: {
      fontWeight: 600,
      marginBottom: children ? '0.15rem' : 0
    }
  }, title), children), onClose && /*#__PURE__*/React.createElement("button", {
    type: "button",
    onClick: onClose,
    "aria-label": "Dismiss",
    style: {
      border: 'none',
      background: 'transparent',
      color: 'var(--color-text-muted)',
      cursor: 'pointer',
      fontSize: '1rem',
      lineHeight: 1
    }
  }, /*#__PURE__*/React.createElement("i", {
    className: "fas fa-times",
    "aria-hidden": "true"
  })));
}
Object.assign(__ds_scope, { Alert });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/core/Alert.jsx", error: String((e && e.message) || e) }); }

// components/core/Avatar.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
function initials(name = '') {
  const parts = name.trim().split(/\s+/).filter(Boolean);
  if (!parts.length) return '?';
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
  return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
}

/* Deterministic muted hue from the name so each person keeps a stable color. */
function hueFor(name = '') {
  let h = 0;
  for (let i = 0; i < name.length; i++) h = name.charCodeAt(i) + ((h << 5) - h);
  return `hsl(${Math.abs(h) % 360} 42% 45%)`;
}

/**
 * Initials avatar (AdminLTE `.avatar-badge`). Falls back to colored
 * initials when no image is given.
 */
function Avatar({
  name = '',
  src,
  size = 32,
  color,
  style,
  ...rest
}) {
  const dim = typeof size === 'number' ? `${size}px` : size;
  const fontSize = `calc(${dim} * 0.42)`;
  return /*#__PURE__*/React.createElement("span", _extends({
    title: name,
    style: {
      display: 'inline-flex',
      alignItems: 'center',
      justifyContent: 'center',
      width: dim,
      height: dim,
      flexShrink: 0,
      borderRadius: 'var(--radius-circle)',
      background: src ? 'transparent' : color || hueFor(name),
      color: '#fff',
      fontFamily: 'var(--font-sans)',
      fontWeight: 700,
      fontSize,
      lineHeight: 1,
      overflow: 'hidden',
      ...style
    }
  }, rest), src ? /*#__PURE__*/React.createElement("img", {
    src: src,
    alt: name,
    style: {
      width: '100%',
      height: '100%',
      objectFit: 'cover'
    }
  }) : initials(name));
}
Object.assign(__ds_scope, { Avatar });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/core/Avatar.jsx", error: String((e && e.message) || e) }); }

// components/core/Badge.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
const HUES = {
  accent: {
    bg: 'var(--color-accent)',
    fg: '#fff'
  },
  success: {
    bg: 'var(--success)',
    fg: '#fff'
  },
  info: {
    bg: 'var(--info)',
    fg: '#fff'
  },
  warning: {
    bg: 'var(--warning)',
    fg: '#212529'
  },
  danger: {
    bg: 'var(--danger)',
    fg: '#fff'
  },
  secondary: {
    bg: 'var(--secondary)',
    fg: '#fff'
  },
  light: {
    bg: 'var(--slate-100)',
    fg: 'var(--color-text)'
  }
};

/**
 * Badge / status pill. `pill` (default) gives the rounded ITFlow
 * status-badge look; `soft` renders a tinted low-contrast variant.
 */
function Badge({
  children,
  color = 'accent',
  pill = true,
  soft = false,
  customColor,
  style,
  ...rest
}) {
  const hue = HUES[color] || HUES.accent;
  const base = customColor ? {
    bg: customColor,
    fg: '#fff'
  } : hue;
  const bg = soft ? `color-mix(in srgb, ${base.bg} 16%, transparent)` : base.bg;
  const fg = soft ? base.bg : base.fg;
  return /*#__PURE__*/React.createElement("span", _extends({
    style: {
      display: 'inline-flex',
      alignItems: 'center',
      gap: '0.35rem',
      padding: '0.35em 0.9em',
      fontFamily: 'var(--font-sans)',
      fontSize: '0.8rem',
      fontWeight: 500,
      lineHeight: 1,
      color: fg,
      background: bg,
      borderRadius: pill ? 'var(--radius-pill)' : 'var(--radius-sm)',
      ...style
    }
  }, rest), children);
}
Object.assign(__ds_scope, { Badge });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/core/Badge.jsx", error: String((e && e.message) || e) }); }

// components/core/Button.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
/**
 * ITFlow primary action button. Maps to Bootstrap `.btn` with the
 * teal "Alga" accent applied to the primary variant.
 */
function Button({
  variant = 'primary',
  size = 'md',
  icon,
  iconRight,
  block = false,
  disabled = false,
  type = 'button',
  children,
  style,
  ...rest
}) {
  const pad = {
    sm: '0.25rem 0.6rem',
    md: '0.375rem 0.85rem',
    lg: '0.5rem 1.1rem'
  }[size];
  const fontSize = {
    sm: '0.78rem',
    md: '0.875rem',
    lg: '1rem'
  }[size];
  const palette = {
    primary: {
      bg: 'var(--color-accent)',
      bd: 'var(--color-accent)',
      fg: '#fff',
      hbg: 'var(--color-accent-hover)',
      shadow: '0 1px 3px rgba(13,148,136,.35)'
    },
    secondary: {
      bg: 'var(--color-surface-alt)',
      bd: 'var(--color-border)',
      fg: 'var(--color-text)',
      hbg: 'var(--color-border-soft)'
    },
    outline: {
      bg: 'transparent',
      bd: 'var(--color-accent)',
      fg: 'var(--color-accent)',
      hbg: 'var(--color-accent-soft)'
    },
    ghost: {
      bg: 'transparent',
      bd: 'transparent',
      fg: 'var(--color-accent)',
      hbg: 'var(--color-accent-soft)'
    },
    danger: {
      bg: 'var(--danger)',
      bd: 'var(--danger)',
      fg: '#fff',
      hbg: '#c82333',
      shadow: '0 1px 3px rgba(220,53,69,.3)'
    },
    dark: {
      bg: 'var(--chrome-bg)',
      bd: 'var(--chrome-bg)',
      fg: '#fff',
      hbg: '#23272b'
    }
  }[variant] || {};
  const [hover, setHover] = React.useState(false);
  return /*#__PURE__*/React.createElement("button", _extends({
    type: type,
    disabled: disabled,
    onMouseEnter: () => setHover(true),
    onMouseLeave: () => setHover(false),
    style: {
      display: block ? 'flex' : 'inline-flex',
      width: block ? '100%' : 'auto',
      alignItems: 'center',
      justifyContent: 'center',
      gap: '0.45rem',
      padding: pad,
      fontSize,
      fontFamily: 'var(--font-sans)',
      fontWeight: 500,
      lineHeight: 1.5,
      color: palette.fg,
      background: hover && !disabled ? palette.hbg : palette.bg,
      border: `1px solid ${palette.bd}`,
      borderRadius: 'var(--card-radius)',
      boxShadow: hover && !disabled ? palette.shadow || 'none' : 'none',
      cursor: disabled ? 'not-allowed' : 'pointer',
      opacity: disabled ? 0.5 : 1,
      transition: 'background var(--transition-fast), color var(--transition-fast)',
      whiteSpace: 'nowrap',
      ...style
    }
  }, rest), icon && /*#__PURE__*/React.createElement("i", {
    className: `fas fa-fw fa-${icon}`,
    "aria-hidden": "true"
  }), children, iconRight && /*#__PURE__*/React.createElement("i", {
    className: `fas fa-fw fa-${iconRight}`,
    "aria-hidden": "true"
  }));
}
Object.assign(__ds_scope, { Button });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/core/Button.jsx", error: String((e && e.message) || e) }); }

// components/core/Card.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
/**
 * Card surface — the workhorse ITFlow container. 14px radius, soft
 * shadow, optional header with title (+ icon) and a tools slot.
 */
function Card({
  title,
  icon,
  tools,
  footer,
  noBody = false,
  bodyStyle,
  children,
  style,
  ...rest
}) {
  return /*#__PURE__*/React.createElement("div", _extends({
    style: {
      background: 'var(--color-surface)',
      border: 'none',
      borderRadius: 'var(--card-radius)',
      boxShadow: 'var(--card-shadow)',
      color: 'var(--color-text)',
      fontFamily: 'var(--font-sans)',
      fontSize: 'var(--text-sm)',
      overflow: 'hidden',
      ...style
    }
  }, rest), (title || tools) && /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      alignItems: 'center',
      justifyContent: 'space-between',
      gap: '0.75rem',
      padding: '0.7rem 1rem',
      borderBottom: '1px solid var(--color-border-soft)'
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      alignItems: 'center',
      gap: '0.5rem',
      fontWeight: 600
    }
  }, icon && /*#__PURE__*/React.createElement("i", {
    className: `fas fa-fw fa-${icon}`,
    style: {
      color: 'var(--color-text-muted)'
    },
    "aria-hidden": "true"
  }), /*#__PURE__*/React.createElement("span", null, title)), tools && /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      alignItems: 'center',
      gap: '0.25rem'
    }
  }, tools)), noBody ? children : /*#__PURE__*/React.createElement("div", {
    style: {
      padding: '1rem',
      ...bodyStyle
    }
  }, children), footer && /*#__PURE__*/React.createElement("div", {
    style: {
      padding: '0.7rem 1rem',
      borderTop: '1px solid var(--color-border-soft)',
      color: 'var(--color-text-muted)'
    }
  }, footer));
}
Object.assign(__ds_scope, { Card });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/core/Card.jsx", error: String((e && e.message) || e) }); }

// components/core/IconButton.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
/**
 * Icon-only button — the AdminLTE `.btn-tool` / card-header action.
 * Square, subtle, used for ellipsis menus, close, refresh, etc.
 */
function IconButton({
  icon,
  variant = 'tool',
  size = 'md',
  disabled = false,
  title,
  style,
  ...rest
}) {
  const dim = {
    sm: '1.6rem',
    md: '2rem',
    lg: '2.4rem'
  }[size];
  const palette = {
    tool: {
      fg: 'var(--color-text-muted)',
      hbg: 'var(--color-border-soft)',
      hfg: 'var(--color-text)'
    },
    primary: {
      fg: '#fff',
      bg: 'var(--color-accent)',
      hbg: 'var(--color-accent-hover)',
      hfg: '#fff'
    },
    danger: {
      fg: 'var(--danger)',
      hbg: 'rgba(220,53,69,.1)',
      hfg: 'var(--danger)'
    }
  }[variant] || {};
  const [hover, setHover] = React.useState(false);
  return /*#__PURE__*/React.createElement("button", _extends({
    type: "button",
    title: title,
    disabled: disabled,
    onMouseEnter: () => setHover(true),
    onMouseLeave: () => setHover(false),
    style: {
      display: 'inline-flex',
      alignItems: 'center',
      justifyContent: 'center',
      width: dim,
      height: dim,
      border: 'none',
      borderRadius: 'var(--radius-sm)',
      background: hover && !disabled ? palette.hbg : palette.bg || 'transparent',
      color: hover && !disabled ? palette.hfg : palette.fg,
      cursor: disabled ? 'not-allowed' : 'pointer',
      opacity: disabled ? 0.5 : 1,
      fontSize: size === 'sm' ? '0.8rem' : '0.95rem',
      transition: 'background var(--transition-fast), color var(--transition-fast)',
      ...style
    }
  }, rest), /*#__PURE__*/React.createElement("i", {
    className: `fas fa-${icon}`,
    "aria-hidden": "true"
  }));
}
Object.assign(__ds_scope, { IconButton });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/core/IconButton.jsx", error: String((e && e.message) || e) }); }

// components/data/PriorityDot.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
const COLORS = {
  high: 'var(--priority-high)',
  medium: 'var(--priority-medium)',
  low: 'var(--priority-low)'
};

/**
 * Colored priority indicator. With `label` it renders the dot plus
 * the capitalized priority name (the ticket-list pattern).
 */
function PriorityDot({
  priority = 'medium',
  label = false,
  style,
  ...rest
}) {
  const key = String(priority).toLowerCase();
  const color = COLORS[key] || 'var(--secondary)';
  const text = key.charAt(0).toUpperCase() + key.slice(1);
  return /*#__PURE__*/React.createElement("span", _extends({
    style: {
      display: 'inline-flex',
      alignItems: 'center',
      gap: '0.4rem',
      fontFamily: 'var(--font-sans)',
      fontSize: 'var(--text-sm)',
      color: 'var(--color-text)',
      ...style
    }
  }, rest), /*#__PURE__*/React.createElement("span", {
    style: {
      width: '0.6rem',
      height: '0.6rem',
      borderRadius: '50%',
      background: color,
      flexShrink: 0
    }
  }), label && text);
}
Object.assign(__ds_scope, { PriorityDot });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/data/PriorityDot.jsx", error: String((e && e.message) || e) }); }

// components/data/SmallBox.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
const HUES = {
  primary: 'var(--color-accent)',
  success: 'var(--success)',
  info: 'var(--info)',
  warning: 'var(--warning)',
  danger: 'var(--danger)',
  secondary: 'var(--secondary)',
  pink: 'var(--pink)',
  violet: 'var(--stat-violet)'
};

/**
 * AdminLTE "small-box" dashboard KPI — saturated color block with a
 * big number, label and an oversized translucent corner icon.
 */
function SmallBox({
  value,
  label,
  icon,
  color = 'primary',
  footer,
  href,
  style,
  ...rest
}) {
  const bg = HUES[color] || HUES.primary;
  const [hover, setHover] = React.useState(false);
  const Comp = href ? 'a' : 'div';
  const textColor = color === 'warning' ? '#1f2d3d' : '#fff';
  return /*#__PURE__*/React.createElement(Comp, _extends({
    href: href,
    onMouseEnter: () => setHover(true),
    onMouseLeave: () => setHover(false),
    style: {
      position: 'relative',
      display: 'block',
      overflow: 'hidden',
      borderRadius: 'var(--input-radius)',
      background: bg,
      color: textColor,
      padding: '0.9rem 1rem 0.7rem',
      minHeight: '6rem',
      textDecoration: 'none',
      fontFamily: 'var(--font-sans)',
      boxShadow: 'var(--card-shadow)',
      filter: hover ? 'brightness(0.94)' : 'none',
      transition: 'filter var(--transition-fast)',
      ...style
    }
  }, rest), /*#__PURE__*/React.createElement("div", {
    style: {
      fontSize: '2.2rem',
      fontWeight: 700,
      lineHeight: 1
    }
  }, value), /*#__PURE__*/React.createElement("div", {
    style: {
      fontSize: '0.9rem',
      marginTop: '0.25rem',
      opacity: 0.95
    }
  }, label), footer && /*#__PURE__*/React.createElement("div", {
    style: {
      fontSize: '0.75rem',
      marginTop: '0.4rem',
      borderTop: '1px solid rgba(255,255,255,.25)',
      paddingTop: '0.3rem',
      opacity: 0.9
    }
  }, footer), icon && /*#__PURE__*/React.createElement("i", {
    className: `fas fa-${icon}`,
    "aria-hidden": "true",
    style: {
      position: 'absolute',
      top: '0.5rem',
      right: '0.75rem',
      fontSize: '3.5rem',
      opacity: 0.3,
      transform: hover ? 'scale(1.08)' : 'none',
      transition: 'transform var(--transition)'
    }
  }));
}
Object.assign(__ds_scope, { SmallBox });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/data/SmallBox.jsx", error: String((e && e.message) || e) }); }

// components/data/StatBox.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
/**
 * Ticket-list stat tile — icon chip, value, label, colored left
 * accent. Clicking applies a filter in the real app. Active state
 * inverts to a filled accent.
 */
function StatBox({
  label,
  value,
  icon,
  color = 'var(--color-accent)',
  active = false,
  href,
  style,
  ...rest
}) {
  const [hover, setHover] = React.useState(false);
  const Comp = href ? 'a' : 'div';
  return /*#__PURE__*/React.createElement(Comp, _extends({
    href: href,
    onMouseEnter: () => setHover(true),
    onMouseLeave: () => setHover(false),
    style: {
      display: 'flex',
      alignItems: 'center',
      gap: '0.75rem',
      textAlign: 'left',
      textDecoration: 'none',
      padding: '0.6rem 0.85rem',
      borderRadius: 'var(--input-radius)',
      background: active ? color : 'var(--color-surface)',
      border: `1px solid ${active ? color : 'var(--color-border)'}`,
      borderLeft: `4px solid ${color}`,
      color: active ? '#fff' : 'var(--color-text)',
      boxShadow: hover ? 'var(--shadow-hover)' : 'var(--card-shadow)',
      transform: hover ? 'translateY(-2px)' : 'none',
      transition: 'transform var(--transition-fast), box-shadow var(--transition-fast)',
      fontFamily: 'var(--font-sans)',
      cursor: href ? 'pointer' : 'default',
      ...style
    }
  }, rest), /*#__PURE__*/React.createElement("span", {
    style: {
      flexShrink: 0,
      display: 'flex',
      alignItems: 'center',
      justifyContent: 'center',
      width: '2.1rem',
      height: '2.1rem',
      borderRadius: 'var(--radius-circle)',
      fontSize: '1rem',
      background: active ? 'rgba(255,255,255,.2)' : `color-mix(in srgb, ${color} 12%, transparent)`,
      color: active ? '#fff' : color
    }
  }, /*#__PURE__*/React.createElement("i", {
    className: `fas fa-fw fa-${icon}`,
    "aria-hidden": "true"
  })), /*#__PURE__*/React.createElement("span", null, /*#__PURE__*/React.createElement("span", {
    style: {
      display: 'block',
      fontSize: '1.3rem',
      fontWeight: 700,
      lineHeight: 1.15
    }
  }, value), /*#__PURE__*/React.createElement("span", {
    style: {
      display: 'block',
      fontSize: '0.7rem',
      textTransform: 'uppercase',
      letterSpacing: 'var(--tracking-label)',
      color: active ? 'rgba(255,255,255,.85)' : 'var(--color-text-muted)'
    }
  }, label)));
}
Object.assign(__ds_scope, { StatBox });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/data/StatBox.jsx", error: String((e && e.message) || e) }); }

// components/forms/Input.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
/**
 * Text input / form control. Optional label, leading icon, and an
 * input-group button (the ITFlow search-field pattern). `pill` gives
 * the rounded filter-bar style.
 */
function Input({
  label,
  icon,
  button,
  pill = false,
  type = 'text',
  id,
  style,
  containerStyle,
  ...rest
}) {
  const radius = pill ? 'var(--radius-pill)' : 'var(--input-radius)';
  const inputId = id || (label ? `in-${Math.random().toString(36).slice(2, 8)}` : undefined);
  return /*#__PURE__*/React.createElement("div", {
    style: {
      fontFamily: 'var(--font-sans)',
      ...containerStyle
    }
  }, label && /*#__PURE__*/React.createElement("label", {
    htmlFor: inputId,
    style: {
      display: 'block',
      fontSize: 'var(--text-sm)',
      fontWeight: 600,
      marginBottom: '0.3rem',
      color: 'var(--color-text)'
    }
  }, label), /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      alignItems: 'stretch',
      position: 'relative'
    }
  }, icon && /*#__PURE__*/React.createElement("i", {
    className: `fas fa-${icon}`,
    "aria-hidden": "true",
    style: {
      position: 'absolute',
      left: '0.75rem',
      top: '50%',
      transform: 'translateY(-50%)',
      color: 'var(--color-text-muted)',
      fontSize: '0.85rem',
      pointerEvents: 'none'
    }
  }), /*#__PURE__*/React.createElement("input", _extends({
    id: inputId,
    type: type,
    style: {
      flex: 1,
      width: '100%',
      padding: icon ? '0.375rem 0.75rem 0.375rem 2rem' : '0.375rem 0.75rem',
      fontFamily: 'var(--font-sans)',
      fontSize: 'var(--text-sm)',
      color: 'var(--color-text)',
      background: 'var(--color-surface)',
      border: '1px solid var(--color-border)',
      borderRadius: button ? `${radius} 0 0 ${radius}` : radius,
      outline: 'none',
      minWidth: 0,
      ...style
    },
    onFocus: e => {
      e.target.style.borderColor = 'var(--color-accent)';
      e.target.style.boxShadow = '0 0 0 2px var(--color-accent-soft)';
    },
    onBlur: e => {
      e.target.style.borderColor = 'var(--color-border)';
      e.target.style.boxShadow = 'none';
    }
  }, rest)), button && /*#__PURE__*/React.createElement("button", {
    type: "button",
    style: {
      display: 'inline-flex',
      alignItems: 'center',
      padding: '0 0.85rem',
      border: '1px solid var(--color-accent)',
      background: 'var(--color-accent)',
      color: '#fff',
      borderRadius: `0 ${radius} ${radius} 0`,
      cursor: 'pointer'
    }
  }, typeof button === 'string' ? /*#__PURE__*/React.createElement("i", {
    className: `fas fa-${button}`,
    "aria-hidden": "true"
  }) : button)));
}
Object.assign(__ds_scope, { Input });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/forms/Input.jsx", error: String((e && e.message) || e) }); }

// components/forms/Select.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
/**
 * Select dropdown (the Select2-styled control). `pill` matches the
 * rounded filter-bar selects on the ticket list.
 */
function Select({
  label,
  options = [],
  placeholder,
  pill = false,
  id,
  style,
  containerStyle,
  ...rest
}) {
  const radius = pill ? 'var(--radius-pill)' : 'var(--input-radius)';
  const selId = id || (label ? `sel-${Math.random().toString(36).slice(2, 8)}` : undefined);
  const norm = options.map(o => typeof o === 'string' ? {
    value: o,
    label: o
  } : o);
  return /*#__PURE__*/React.createElement("div", {
    style: {
      fontFamily: 'var(--font-sans)',
      ...containerStyle
    }
  }, label && /*#__PURE__*/React.createElement("label", {
    htmlFor: selId,
    style: {
      display: 'block',
      fontSize: 'var(--text-sm)',
      fontWeight: 600,
      marginBottom: '0.3rem',
      color: 'var(--color-text)'
    }
  }, label), /*#__PURE__*/React.createElement("div", {
    style: {
      position: 'relative'
    }
  }, /*#__PURE__*/React.createElement("select", _extends({
    id: selId,
    style: {
      width: '100%',
      appearance: 'none',
      WebkitAppearance: 'none',
      padding: pill ? '0.45rem 2rem 0.45rem 1rem' : '0.375rem 2rem 0.375rem 0.75rem',
      fontFamily: 'var(--font-sans)',
      fontSize: 'var(--text-sm)',
      color: 'var(--color-text)',
      background: 'var(--color-surface)',
      border: '1px solid var(--color-border)',
      borderRadius: radius,
      outline: 'none',
      cursor: 'pointer',
      ...style
    },
    onFocus: e => {
      e.target.style.borderColor = 'var(--color-accent)';
      e.target.style.boxShadow = '0 0 0 2px var(--color-accent-soft)';
    },
    onBlur: e => {
      e.target.style.borderColor = 'var(--color-border)';
      e.target.style.boxShadow = 'none';
    }
  }, rest), placeholder && /*#__PURE__*/React.createElement("option", {
    value: ""
  }, placeholder), norm.map(o => /*#__PURE__*/React.createElement("option", {
    key: o.value,
    value: o.value
  }, o.label))), /*#__PURE__*/React.createElement("i", {
    className: "fas fa-chevron-down",
    "aria-hidden": "true",
    style: {
      position: 'absolute',
      right: '0.85rem',
      top: '50%',
      transform: 'translateY(-50%)',
      color: 'var(--color-text-muted)',
      fontSize: '0.7rem',
      pointerEvents: 'none'
    }
  })));
}
Object.assign(__ds_scope, { Select });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/forms/Select.jsx", error: String((e && e.message) || e) }); }

// components/forms/Switch.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
/**
 * Toggle switch (Bootstrap custom-switch) with the teal accent when on.
 */
function Switch({
  checked,
  defaultChecked,
  onChange,
  label,
  disabled = false,
  id,
  style,
  ...rest
}) {
  const isControlled = checked !== undefined;
  const [internal, setInternal] = React.useState(!!defaultChecked);
  const on = isControlled ? checked : internal;
  const swId = id || `sw-${Math.random().toString(36).slice(2, 8)}`;
  const toggle = () => {
    if (disabled) return;
    if (!isControlled) setInternal(v => !v);
    onChange && onChange(!on);
  };
  return /*#__PURE__*/React.createElement("label", _extends({
    htmlFor: swId,
    style: {
      display: 'inline-flex',
      alignItems: 'center',
      gap: '0.55rem',
      fontFamily: 'var(--font-sans)',
      fontSize: 'var(--text-sm)',
      color: 'var(--color-text)',
      cursor: disabled ? 'not-allowed' : 'pointer',
      opacity: disabled ? 0.55 : 1,
      ...style
    }
  }, rest), /*#__PURE__*/React.createElement("input", {
    id: swId,
    type: "checkbox",
    checked: on,
    onChange: toggle,
    disabled: disabled,
    style: {
      position: 'absolute',
      opacity: 0,
      width: 0,
      height: 0
    }
  }), /*#__PURE__*/React.createElement("span", {
    "aria-hidden": "true",
    style: {
      position: 'relative',
      width: '2.25rem',
      height: '1.25rem',
      borderRadius: 'var(--radius-pill)',
      background: on ? 'var(--color-accent)' : 'var(--slate-300)',
      transition: 'background var(--transition)',
      flexShrink: 0
    }
  }, /*#__PURE__*/React.createElement("span", {
    style: {
      position: 'absolute',
      top: '2px',
      left: on ? 'calc(100% - 1rem - 2px)' : '2px',
      width: '1rem',
      height: '1rem',
      borderRadius: '50%',
      background: '#fff',
      boxShadow: '0 1px 2px rgba(0,0,0,.3)',
      transition: 'left var(--transition)'
    }
  })), label && /*#__PURE__*/React.createElement("span", null, label));
}
Object.assign(__ds_scope, { Switch });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/forms/Switch.jsx", error: String((e && e.message) || e) }); }

// ui_kits/itflow/AdminScreens.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
/* Admin — Company Details settings form (mirrors admin/settings_company.php).
   Uses the AdminShell (dark sidebar with "← Back | Administration" header). */

function AdminSettings({
  onNavigate
}) {
  const D = window.ITF_DATA;
  const {
    Card,
    Button,
    Input,
    Select
  } = window.ITFlow_b1b893;
  const [saved, setSaved] = React.useState(false);
  const countries = ['United States', 'Canada', 'United Kingdom', 'Australia', 'Germany', 'France', 'Netherlands', 'New Zealand'];
  function FormGroup({
    label,
    icon,
    children
  }) {
    return /*#__PURE__*/React.createElement("div", {
      style: {
        marginBottom: 16
      }
    }, /*#__PURE__*/React.createElement("label", {
      style: {
        display: 'block',
        fontSize: 13,
        fontWeight: 600,
        marginBottom: 6,
        color: 'var(--color-text)'
      }
    }, label), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'stretch',
        border: '1px solid var(--color-border)',
        borderRadius: 'var(--input-radius)',
        overflow: 'hidden',
        background: 'var(--color-surface)'
      }
    }, /*#__PURE__*/React.createElement("span", {
      style: {
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        width: 38,
        background: 'var(--color-surface-alt)',
        borderRight: '1px solid var(--color-border)',
        color: 'var(--color-text-muted)',
        flexShrink: 0
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: `fas fa-fw fa-${icon}`
    })), /*#__PURE__*/React.createElement("div", {
      style: {
        flex: 1,
        minWidth: 0
      }
    }, children)));
  }
  function FieldInput(props) {
    return /*#__PURE__*/React.createElement("input", _extends({
      style: {
        width: '100%',
        border: 'none',
        outline: 'none',
        padding: '0.375rem 0.75rem',
        fontSize: 13,
        fontFamily: 'var(--font-sans)',
        color: 'var(--color-text)',
        background: 'transparent',
        boxSizing: 'border-box'
      }
    }, props));
  }
  return /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      alignItems: 'center',
      gap: 12,
      marginBottom: 18
    }
  }, /*#__PURE__*/React.createElement("h1", {
    style: {
      margin: 0,
      fontSize: 22,
      fontWeight: 700,
      color: 'var(--color-text)'
    }
  }, "Administration")), /*#__PURE__*/React.createElement("div", {
    style: {
      background: 'var(--color-surface)',
      borderRadius: 'var(--card-radius)',
      boxShadow: 'var(--card-shadow)',
      overflow: 'hidden'
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      background: 'var(--chrome-bg)',
      color: '#fff',
      padding: '12px 16px',
      display: 'flex',
      alignItems: 'center',
      gap: 10
    }
  }, /*#__PURE__*/React.createElement("i", {
    className: "fas fa-fw fa-briefcase"
  }), /*#__PURE__*/React.createElement("span", {
    style: {
      fontWeight: 600
    }
  }, "Company Details")), /*#__PURE__*/React.createElement("div", {
    style: {
      padding: 20
    }
  }, saved && /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      alignItems: 'center',
      gap: 8,
      padding: '10px 14px',
      background: 'color-mix(in srgb, var(--success) 12%, transparent)',
      border: '1px solid color-mix(in srgb, var(--success) 30%, transparent)',
      borderRadius: 'var(--input-radius)',
      marginBottom: 20,
      fontSize: 13,
      color: 'var(--color-text)'
    }
  }, /*#__PURE__*/React.createElement("i", {
    className: "fas fa-check-circle",
    style: {
      color: 'var(--success)'
    }
  }), " Company details saved successfully."), /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'grid',
      gridTemplateColumns: '200px 1fr',
      gap: 24
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      textAlign: 'center'
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      width: 160,
      height: 160,
      borderRadius: 'var(--card-radius)',
      border: '2px dashed var(--color-border)',
      display: 'flex',
      flexDirection: 'column',
      alignItems: 'center',
      justifyContent: 'center',
      color: 'var(--color-text-muted)',
      margin: '0 auto 12px',
      background: 'var(--color-surface-alt)',
      cursor: 'pointer'
    }
  }, /*#__PURE__*/React.createElement("i", {
    className: "fas fa-cloud-upload-alt",
    style: {
      fontSize: 28,
      marginBottom: 8
    }
  }), /*#__PURE__*/React.createElement("span", {
    style: {
      fontSize: 12
    }
  }, "Upload logo"), /*#__PURE__*/React.createElement("span", {
    style: {
      fontSize: 11,
      marginTop: 4,
      opacity: .7
    }
  }, "JPG / PNG")), /*#__PURE__*/React.createElement("div", {
    style: {
      fontSize: 11,
      color: 'var(--color-text-muted)'
    }
  }, "Shown on invoices and the client portal")), /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement(FormGroup, {
    label: /*#__PURE__*/React.createElement(React.Fragment, null, "Name ", /*#__PURE__*/React.createElement("strong", {
      style: {
        color: 'var(--danger)'
      }
    }, "*")),
    icon: "building"
  }, /*#__PURE__*/React.createElement(FieldInput, {
    defaultValue: D.company,
    placeholder: "Company Name"
  })), /*#__PURE__*/React.createElement(FormGroup, {
    label: "Address",
    icon: "map-marker-alt"
  }, /*#__PURE__*/React.createElement(FieldInput, {
    defaultValue: "1420 Harbor View Dr",
    placeholder: "Street Address"
  })), /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'grid',
      gridTemplateColumns: '1fr 1fr',
      gap: 12
    }
  }, /*#__PURE__*/React.createElement(FormGroup, {
    label: "City",
    icon: "city"
  }, /*#__PURE__*/React.createElement(FieldInput, {
    defaultValue: "Portland",
    placeholder: "City"
  })), /*#__PURE__*/React.createElement(FormGroup, {
    label: "State / Province",
    icon: "flag"
  }, /*#__PURE__*/React.createElement(FieldInput, {
    defaultValue: "OR",
    placeholder: "State or Province"
  }))), /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'grid',
      gridTemplateColumns: '1fr 1fr',
      gap: 12
    }
  }, /*#__PURE__*/React.createElement(FormGroup, {
    label: "Postal Code",
    icon: "envelope"
  }, /*#__PURE__*/React.createElement(FieldInput, {
    defaultValue: "97201",
    placeholder: "Zip or Postal Code"
  })), /*#__PURE__*/React.createElement("div", {
    style: {
      marginBottom: 16
    }
  }, /*#__PURE__*/React.createElement("label", {
    style: {
      display: 'block',
      fontSize: 13,
      fontWeight: 600,
      marginBottom: 6,
      color: 'var(--color-text)'
    }
  }, "Country"), /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      alignItems: 'stretch',
      border: '1px solid var(--color-border)',
      borderRadius: 'var(--input-radius)',
      overflow: 'hidden',
      background: 'var(--color-surface)'
    }
  }, /*#__PURE__*/React.createElement("span", {
    style: {
      display: 'flex',
      alignItems: 'center',
      justifyContent: 'center',
      width: 38,
      background: 'var(--color-surface-alt)',
      borderRight: '1px solid var(--color-border)',
      color: 'var(--color-text-muted)',
      flexShrink: 0
    }
  }, /*#__PURE__*/React.createElement("i", {
    className: "fas fa-fw fa-globe-americas"
  })), /*#__PURE__*/React.createElement("select", {
    defaultValue: "United States",
    style: {
      flex: 1,
      border: 'none',
      outline: 'none',
      padding: '0.375rem 0.75rem',
      fontSize: 13,
      fontFamily: 'var(--font-sans)',
      color: 'var(--color-text)',
      background: 'transparent'
    }
  }, countries.map(c => /*#__PURE__*/React.createElement("option", {
    key: c
  }, c)))))), /*#__PURE__*/React.createElement(FormGroup, {
    label: "Phone",
    icon: "phone"
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex'
    }
  }, /*#__PURE__*/React.createElement(FieldInput, {
    defaultValue: "+1",
    placeholder: "+",
    style: {
      width: 56,
      borderRight: '1px solid var(--color-border)',
      flexShrink: 0
    }
  }), /*#__PURE__*/React.createElement(FieldInput, {
    defaultValue: "(503) 555-0188",
    placeholder: "Phone Number",
    style: {
      flex: 1
    }
  }))), /*#__PURE__*/React.createElement(FormGroup, {
    label: "Email",
    icon: "envelope"
  }, /*#__PURE__*/React.createElement(FieldInput, {
    type: "email",
    defaultValue: "hello@foleyit.com",
    placeholder: "Email address"
  })), /*#__PURE__*/React.createElement(FormGroup, {
    label: "Website",
    icon: "globe"
  }, /*#__PURE__*/React.createElement(FieldInput, {
    defaultValue: "https://foleyit.com",
    placeholder: "Website address"
  })), /*#__PURE__*/React.createElement(FormGroup, {
    label: "Tax ID",
    icon: "balance-scale"
  }, /*#__PURE__*/React.createElement(FieldInput, {
    defaultValue: "47-0382918",
    placeholder: "Tax ID"
  })), /*#__PURE__*/React.createElement("hr", {
    style: {
      border: 'none',
      borderTop: '1px solid var(--color-border)',
      margin: '20px 0'
    }
  }), /*#__PURE__*/React.createElement(Button, {
    icon: "check",
    onClick: () => {
      setSaved(true);
      setTimeout(() => setSaved(false), 3000);
    }
  }, "Save"))))));
}

/* Admin placeholder for other settings pages */
function AdminPlaceholder({
  id,
  onNavigate
}) {
  const D = window.ITF_DATA;
  const allItems = D.adminNav.flatMap(n => n.type === 'group' ? n.children : n.type === 'item' ? [n] : []);
  const item = allItems.find(n => n.id === id);
  const {
    Card
  } = window.ITFlow_b1b893;
  return /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement("h1", {
    style: {
      margin: '0 0 16px',
      fontSize: 22,
      fontWeight: 700,
      color: 'var(--color-text)'
    }
  }, item ? item.label : id), /*#__PURE__*/React.createElement(Card, null, /*#__PURE__*/React.createElement("div", {
    style: {
      textAlign: 'center',
      padding: '50px 20px',
      color: 'var(--color-text-muted)'
    }
  }, /*#__PURE__*/React.createElement("i", {
    className: `fas fa-${item ? item.icon : 'cog'}`,
    style: {
      fontSize: 40,
      color: 'var(--color-border)'
    }
  }), /*#__PURE__*/React.createElement("p", {
    style: {
      marginTop: 14,
      fontSize: 14
    }
  }, "This admin module isn't part of this UI-kit demo."), /*#__PURE__*/React.createElement("p", {
    style: {
      fontSize: 13
    }
  }, "Explore ", /*#__PURE__*/React.createElement("strong", null, "Company Details"), " for a full interactive example."))));
}
Object.assign(window, {
  AdminSettings,
  AdminPlaceholder
});
})(); } catch (e) { __ds_ns.__errors.push({ path: "ui_kits/itflow/AdminScreens.jsx", error: String((e && e.message) || e) }); }

// ui_kits/itflow/Clients.jsx
try { (() => {
/* Clients list — searchable table of managed clients. */
function Clients({
  onNavigate
}) {
  const D = window.ITF_DATA;
  const {
    Card,
    Button,
    Input,
    Badge,
    Avatar
  } = window.ITFlow_b1b893;
  const [q, setQ] = React.useState('');
  const rows = D.clients.filter(c => c.name.toLowerCase().includes(q.toLowerCase()));
  const fmt = n => '$' + n.toLocaleString();
  return /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      alignItems: 'center',
      justifyContent: 'space-between',
      marginBottom: 16
    }
  }, /*#__PURE__*/React.createElement("h1", {
    style: {
      margin: 0,
      fontSize: 22,
      fontWeight: 700,
      color: 'var(--color-text)'
    }
  }, "Clients"), /*#__PURE__*/React.createElement(Button, {
    icon: "plus"
  }, "New Client")), /*#__PURE__*/React.createElement(Card, {
    noBody: true
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      padding: 14,
      borderBottom: '1px solid var(--color-border-soft)'
    }
  }, /*#__PURE__*/React.createElement(Input, {
    pill: true,
    icon: "search",
    placeholder: "Search clients...",
    value: q,
    onChange: e => setQ(e.target.value),
    containerStyle: {
      maxWidth: 320
    }
  })), /*#__PURE__*/React.createElement("table", {
    style: {
      width: '100%',
      borderCollapse: 'collapse',
      fontSize: 13
    }
  }, /*#__PURE__*/React.createElement("thead", null, /*#__PURE__*/React.createElement("tr", {
    style: {
      textAlign: 'left',
      color: 'var(--color-text-muted)',
      fontSize: 11,
      textTransform: 'uppercase',
      letterSpacing: '.03em'
    }
  }, /*#__PURE__*/React.createElement("th", {
    style: {
      padding: '10px 16px',
      fontWeight: 600
    }
  }, "Client"), /*#__PURE__*/React.createElement("th", {
    style: {
      padding: '10px 8px',
      fontWeight: 600
    }
  }, "Primary Contact"), /*#__PURE__*/React.createElement("th", {
    style: {
      padding: '10px 8px',
      fontWeight: 600
    }
  }, "Type"), /*#__PURE__*/React.createElement("th", {
    style: {
      padding: '10px 8px',
      fontWeight: 600,
      textAlign: 'right'
    }
  }, "Open Tickets"), /*#__PURE__*/React.createElement("th", {
    style: {
      padding: '10px 8px',
      fontWeight: 600,
      textAlign: 'right'
    }
  }, "Assets"), /*#__PURE__*/React.createElement("th", {
    style: {
      padding: '10px 8px',
      fontWeight: 600,
      textAlign: 'right'
    }
  }, "Balance"), /*#__PURE__*/React.createElement("th", {
    style: {
      padding: '10px 16px',
      fontWeight: 600,
      textAlign: 'right'
    }
  }, "MRR"))), /*#__PURE__*/React.createElement("tbody", null, rows.map(c => /*#__PURE__*/React.createElement("tr", {
    key: c.name,
    style: {
      borderTop: '1px solid var(--color-border-soft)',
      cursor: 'pointer'
    },
    onMouseEnter: e => e.currentTarget.style.background = 'var(--color-accent-soft)',
    onMouseLeave: e => e.currentTarget.style.background = 'transparent'
  }, /*#__PURE__*/React.createElement("td", {
    style: {
      padding: '11px 16px',
      fontWeight: 600
    }
  }, /*#__PURE__*/React.createElement("span", {
    style: {
      display: 'inline-flex',
      alignItems: 'center',
      gap: 10
    }
  }, /*#__PURE__*/React.createElement(Avatar, {
    name: c.name,
    size: 28
  }), c.name)), /*#__PURE__*/React.createElement("td", {
    style: {
      padding: '11px 8px',
      color: 'var(--color-text-muted)'
    }
  }, c.contact), /*#__PURE__*/React.createElement("td", {
    style: {
      padding: '11px 8px'
    }
  }, /*#__PURE__*/React.createElement(Badge, {
    soft: true,
    color: c.tag === 'Managed' ? 'accent' : 'secondary'
  }, c.tag)), /*#__PURE__*/React.createElement("td", {
    style: {
      padding: '11px 8px',
      textAlign: 'right'
    }
  }, c.tickets || '—'), /*#__PURE__*/React.createElement("td", {
    style: {
      padding: '11px 8px',
      textAlign: 'right',
      fontFamily: 'var(--font-mono)'
    }
  }, c.assets), /*#__PURE__*/React.createElement("td", {
    style: {
      padding: '11px 8px',
      textAlign: 'right',
      color: c.balance > 0 ? 'var(--danger)' : 'var(--color-text)'
    }
  }, fmt(c.balance)), /*#__PURE__*/React.createElement("td", {
    style: {
      padding: '11px 16px',
      textAlign: 'right',
      fontWeight: 600
    }
  }, fmt(c.mrr))))))));
}
Object.assign(window, {
  Clients
});
})(); } catch (e) { __ds_ns.__errors.push({ path: "ui_kits/itflow/Clients.jsx", error: String((e && e.message) || e) }); }

// ui_kits/itflow/Dashboard.jsx
try { (() => {
/* Dashboard — greeting, small-box KPIs, charts (CSS bars) and recent tickets. */
function Dashboard({
  onNavigate
}) {
  const D = window.ITF_DATA;
  const {
    SmallBox,
    Card,
    Switch,
    Badge,
    PriorityDot,
    Avatar
  } = window.ITFlow_b1b893;
  const hour = new Date().getHours();
  const greet = hour < 12 ? 'Good morning' : hour < 17 ? 'Good afternoon' : 'Good evening';
  const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  const opened = [22, 28, 24, 31, 27, 34, 29, 36, 30, 33, 38, 26];
  const resolved = [20, 25, 26, 28, 29, 30, 31, 33, 29, 35, 34, 30];
  const max = 40;
  return /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement("div", {
    style: {
      marginBottom: 18
    }
  }, /*#__PURE__*/React.createElement("h1", {
    style: {
      margin: 0,
      fontSize: 24,
      fontWeight: 700,
      color: 'var(--color-text)'
    }
  }, greet, ", ", D.user.name.split(' ')[0], "!"), /*#__PURE__*/React.createElement("div", {
    style: {
      fontSize: 13,
      color: 'var(--color-text-muted)'
    }
  }, new Date().toLocaleDateString('en-US', {
    weekday: 'long',
    year: 'numeric',
    month: 'long',
    day: 'numeric'
  }))), /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'grid',
      gridTemplateColumns: 'repeat(4, 1fr)',
      gap: 14,
      marginBottom: 16
    }
  }, /*#__PURE__*/React.createElement(SmallBox, {
    value: 42,
    label: "Open Tickets",
    icon: "ticket-alt",
    color: "primary",
    href: "#",
    onClick: e => {
      e.preventDefault();
      onNavigate('tickets');
    }
  }), /*#__PURE__*/React.createElement(SmallBox, {
    value: 9,
    label: "My Tickets",
    icon: "user-check",
    color: "violet",
    href: "#",
    onClick: e => {
      e.preventDefault();
      onNavigate('tickets');
    }
  }), /*#__PURE__*/React.createElement(SmallBox, {
    value: 38,
    label: "Active Clients",
    icon: "building",
    color: "success",
    href: "#",
    onClick: e => {
      e.preventDefault();
      onNavigate('clients');
    }
  }), /*#__PURE__*/React.createElement(SmallBox, {
    value: 6,
    label: "Unpaid Invoices",
    icon: "file-invoice-dollar",
    color: "warning",
    href: "#"
  })), /*#__PURE__*/React.createElement("div", {
    style: {
      background: 'var(--color-surface)',
      borderRadius: 'var(--card-radius)',
      boxShadow: 'var(--card-shadow)',
      padding: '12px 16px',
      marginBottom: 16,
      display: 'flex',
      alignItems: 'center',
      gap: 22
    }
  }, /*#__PURE__*/React.createElement("span", {
    style: {
      fontSize: 13,
      fontWeight: 600
    }
  }, "2026"), /*#__PURE__*/React.createElement(Switch, {
    label: "Financial",
    defaultChecked: true
  }), /*#__PURE__*/React.createElement(Switch, {
    label: "Technical",
    defaultChecked: true
  })), /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'grid',
      gridTemplateColumns: '2fr 1fr',
      gap: 16,
      marginBottom: 16
    }
  }, /*#__PURE__*/React.createElement(Card, {
    title: "Tickets Opened vs Resolved",
    icon: "chart-line"
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      alignItems: 'flex-end',
      gap: 10,
      height: 210,
      padding: '4px 0'
    }
  }, months.map((m, i) => /*#__PURE__*/React.createElement("div", {
    key: m,
    style: {
      flex: 1,
      display: 'flex',
      flexDirection: 'column',
      alignItems: 'center',
      gap: 4
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      alignItems: 'flex-end',
      gap: 3,
      height: 180,
      width: '100%',
      justifyContent: 'center'
    }
  }, /*#__PURE__*/React.createElement("div", {
    title: `Opened ${opened[i]}`,
    style: {
      width: '38%',
      height: `${opened[i] / max * 100}%`,
      background: 'var(--color-accent)',
      borderRadius: '3px 3px 0 0'
    }
  }), /*#__PURE__*/React.createElement("div", {
    title: `Resolved ${resolved[i]}`,
    style: {
      width: '38%',
      height: `${resolved[i] / max * 100}%`,
      background: 'var(--slate-300)',
      borderRadius: '3px 3px 0 0'
    }
  })), /*#__PURE__*/React.createElement("span", {
    style: {
      fontSize: 10,
      color: 'var(--color-text-muted)'
    }
  }, m)))), /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      gap: 18,
      fontSize: 12,
      color: 'var(--color-text-muted)',
      marginTop: 6
    }
  }, /*#__PURE__*/React.createElement("span", null, /*#__PURE__*/React.createElement("i", {
    className: "fas fa-square",
    style: {
      color: 'var(--color-accent)',
      marginRight: 5
    }
  }), "Opened"), /*#__PURE__*/React.createElement("span", null, /*#__PURE__*/React.createElement("i", {
    className: "fas fa-square",
    style: {
      color: 'var(--slate-300)',
      marginRight: 5
    }
  }), "Resolved"))), /*#__PURE__*/React.createElement(Card, {
    title: "By Priority",
    icon: "chart-pie"
  }, [['High', 14, 'var(--priority-high)'], ['Medium', 19, 'var(--priority-medium)'], ['Low', 9, 'var(--priority-low)']].map(([l, v, c]) => /*#__PURE__*/React.createElement("div", {
    key: l,
    style: {
      marginBottom: 12
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      justifyContent: 'space-between',
      fontSize: 13,
      marginBottom: 4
    }
  }, /*#__PURE__*/React.createElement("span", null, l), /*#__PURE__*/React.createElement("strong", null, v)), /*#__PURE__*/React.createElement("div", {
    style: {
      height: 8,
      borderRadius: 4,
      background: 'var(--slate-100)'
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      width: `${v / 42 * 100}%`,
      height: '100%',
      borderRadius: 4,
      background: c
    }
  })))), /*#__PURE__*/React.createElement("div", {
    style: {
      marginTop: 18,
      paddingTop: 14,
      borderTop: '1px solid var(--color-border-soft)',
      display: 'flex',
      justifyContent: 'space-between',
      fontSize: 13
    }
  }, /*#__PURE__*/React.createElement("span", {
    style: {
      color: 'var(--color-text-muted)'
    }
  }, "Avg. resolution"), /*#__PURE__*/React.createElement("strong", null, "5.2 h")))), /*#__PURE__*/React.createElement(Card, {
    title: "My Active Tickets",
    icon: "user-check",
    tools: /*#__PURE__*/React.createElement("a", {
      onClick: () => onNavigate('tickets'),
      style: {
        cursor: 'pointer',
        color: 'var(--color-accent)',
        fontSize: 13
      }
    }, "View all"),
    noBody: true
  }, /*#__PURE__*/React.createElement("table", {
    style: {
      width: '100%',
      borderCollapse: 'collapse',
      fontSize: 13
    }
  }, /*#__PURE__*/React.createElement("tbody", null, D.tickets.filter(t => t.assignee === D.user.name || !t.assignee).slice(0, 4).map(t => /*#__PURE__*/React.createElement("tr", {
    key: t.id,
    style: {
      borderTop: '1px solid var(--color-border-soft)',
      cursor: 'pointer'
    },
    onClick: () => onNavigate('ticket')
  }, /*#__PURE__*/React.createElement("td", {
    style: {
      padding: '10px 16px',
      color: 'var(--color-text-muted)',
      fontFamily: 'var(--font-mono)',
      width: 60
    }
  }, "#", t.id), /*#__PURE__*/React.createElement("td", {
    style: {
      padding: '10px 8px',
      fontWeight: 500
    }
  }, t.subject), /*#__PURE__*/React.createElement("td", {
    style: {
      padding: '10px 8px',
      color: 'var(--color-text-muted)'
    }
  }, t.client), /*#__PURE__*/React.createElement("td", {
    style: {
      padding: '10px 8px'
    }
  }, /*#__PURE__*/React.createElement(PriorityDot, {
    priority: t.priority,
    label: true
  })), /*#__PURE__*/React.createElement("td", {
    style: {
      padding: '10px 16px',
      textAlign: 'right'
    }
  }, /*#__PURE__*/React.createElement(Badge, {
    soft: true,
    customColor: t.statusColor
  }, t.status))))))));
}
Object.assign(window, {
  Dashboard
});
})(); } catch (e) { __ds_ns.__errors.push({ path: "ui_kits/itflow/Dashboard.jsx", error: String((e && e.message) || e) }); }

// ui_kits/itflow/Login.jsx
try { (() => {
/* Login screen — matches ITFlow's centered card on a teal-tinted page. */
function Login({
  onLogin
}) {
  const D = window.ITF_DATA;
  const {
    Button,
    Input
  } = window.ITFlow_b1b893;
  return /*#__PURE__*/React.createElement("div", {
    style: {
      minHeight: '100vh',
      display: 'flex',
      alignItems: 'center',
      justifyContent: 'center',
      fontFamily: 'var(--font-sans)',
      background: 'var(--chrome-bg)',
      backgroundImage: 'radial-gradient(circle at 30% 20%, rgba(13,148,136,.35), transparent 55%), radial-gradient(circle at 80% 90%, rgba(13,148,136,.20), transparent 50%)',
      padding: 20
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      width: 380,
      maxWidth: '100%'
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      textAlign: 'center',
      marginBottom: 22,
      color: '#fff'
    }
  }, /*#__PURE__*/React.createElement("span", {
    style: {
      display: 'inline-flex',
      width: 52,
      height: 52,
      borderRadius: 13,
      background: 'var(--color-accent)',
      alignItems: 'center',
      justifyContent: 'center'
    }
  }, /*#__PURE__*/React.createElement("i", {
    className: "fas fa-bolt",
    style: {
      fontSize: 26,
      color: '#fff'
    }
  })), /*#__PURE__*/React.createElement("div", {
    style: {
      fontSize: 26,
      fontWeight: 700,
      marginTop: 12
    }
  }, D.company), /*#__PURE__*/React.createElement("div", {
    style: {
      fontSize: 13,
      opacity: .65
    }
  }, "ITFlow \xB7 MSP Edition")), /*#__PURE__*/React.createElement("div", {
    style: {
      background: 'var(--color-surface)',
      borderRadius: 'var(--card-radius)',
      boxShadow: 'var(--shadow-hover)',
      padding: 26
    }
  }, /*#__PURE__*/React.createElement("h2", {
    style: {
      margin: '0 0 4px',
      fontSize: 20,
      color: 'var(--color-text)'
    }
  }, "Sign in"), /*#__PURE__*/React.createElement("p", {
    style: {
      margin: '0 0 18px',
      fontSize: 13,
      color: 'var(--color-text-muted)'
    }
  }, "Welcome back. Please enter your details."), /*#__PURE__*/React.createElement("form", {
    onSubmit: e => {
      e.preventDefault();
      onLogin();
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      marginBottom: 14
    }
  }, /*#__PURE__*/React.createElement(Input, {
    label: "Email",
    type: "email",
    icon: "envelope",
    defaultValue: "jane@foleyit.com",
    placeholder: "you@company.com"
  })), /*#__PURE__*/React.createElement("div", {
    style: {
      marginBottom: 18
    }
  }, /*#__PURE__*/React.createElement(Input, {
    label: "Password",
    type: "password",
    icon: "lock",
    defaultValue: "password",
    placeholder: "\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022"
  })), /*#__PURE__*/React.createElement(Button, {
    type: "submit",
    block: true
  }, "Sign in")), /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      alignItems: 'center',
      gap: 10,
      margin: '16px 0'
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      flex: 1,
      height: 1,
      background: 'var(--color-border)'
    }
  }), /*#__PURE__*/React.createElement("span", {
    style: {
      fontSize: 12,
      color: 'var(--color-text-muted)'
    }
  }, "or"), /*#__PURE__*/React.createElement("div", {
    style: {
      flex: 1,
      height: 1,
      background: 'var(--color-border)'
    }
  })), /*#__PURE__*/React.createElement(Button, {
    variant: "secondary",
    block: true,
    icon: "fingerprint",
    onClick: onLogin
  }, "Sign in with passkey")), /*#__PURE__*/React.createElement("p", {
    style: {
      textAlign: 'center',
      fontSize: 12,
      color: 'rgba(255,255,255,.5)',
      marginTop: 18
    }
  }, "Looking for the client portal? ", /*#__PURE__*/React.createElement("span", {
    style: {
      color: 'var(--teal-300)',
      cursor: 'pointer'
    }
  }, "Go here"))));
}
Object.assign(window, {
  Login
});
})(); } catch (e) { __ds_ns.__errors.push({ path: "ui_kits/itflow/Login.jsx", error: String((e && e.message) || e) }); }

// ui_kits/itflow/Shell.jsx
try { (() => {
/* App shell: dark sidebar + top navbar + content wrapper (AdminLTE chrome). */
const {
  useState
} = React;
function NavItem({
  n,
  active,
  onNavigate
}) {
  const on = active === n.id;
  return /*#__PURE__*/React.createElement("a", {
    onClick: () => onNavigate(n.id),
    style: {
      display: 'flex',
      alignItems: 'center',
      gap: 11,
      padding: '9px 12px',
      margin: '1px 0',
      borderRadius: 6,
      cursor: 'pointer',
      color: on ? '#fff' : 'rgba(255,255,255,.82)',
      background: on ? 'var(--color-accent)' : 'transparent',
      fontSize: 14,
      whiteSpace: 'nowrap',
      textDecoration: 'none'
    },
    onMouseEnter: e => {
      if (!on) e.currentTarget.style.background = 'rgba(255,255,255,.08)';
    },
    onMouseLeave: e => {
      if (!on) e.currentTarget.style.background = 'transparent';
    }
  }, /*#__PURE__*/React.createElement("i", {
    className: `fas fa-fw fa-${n.icon}`,
    style: {
      width: 20,
      textAlign: 'center'
    }
  }), /*#__PURE__*/React.createElement("span", {
    style: {
      flex: 1
    }
  }, n.label), n.arrow && /*#__PURE__*/React.createElement("i", {
    className: "fas fa-angle-right",
    style: {
      fontSize: 11,
      opacity: .6
    }
  }), n.badge != null && /*#__PURE__*/React.createElement("span", {
    style: {
      fontSize: 11,
      fontWeight: 700,
      padding: '1px 7px',
      borderRadius: 10,
      background: n.badgeColor || 'rgba(255,255,255,.16)',
      color: '#fff'
    }
  }, n.badge));
}
function NavGroup({
  n,
  active,
  onNavigate
}) {
  const childActive = n.children && n.children.some(c => c.id === active);
  const [open, setOpen] = useState(!!(n.open || childActive));
  return /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement("a", {
    onClick: () => setOpen(o => !o),
    style: {
      display: 'flex',
      alignItems: 'center',
      gap: 11,
      padding: '9px 12px',
      margin: '1px 0',
      borderRadius: 6,
      cursor: 'pointer',
      color: 'rgba(255,255,255,.82)',
      fontSize: 14,
      whiteSpace: 'nowrap',
      textDecoration: 'none',
      background: childActive ? 'rgba(255,255,255,.06)' : 'transparent'
    },
    onMouseEnter: e => e.currentTarget.style.background = 'rgba(255,255,255,.08)',
    onMouseLeave: e => e.currentTarget.style.background = childActive ? 'rgba(255,255,255,.06)' : 'transparent'
  }, /*#__PURE__*/React.createElement("i", {
    className: `fas fa-fw fa-${n.icon}`,
    style: {
      width: 20,
      textAlign: 'center'
    }
  }), /*#__PURE__*/React.createElement("span", {
    style: {
      flex: 1
    }
  }, n.label), /*#__PURE__*/React.createElement("i", {
    className: `fas fa-angle-${open ? 'down' : 'left'}`,
    style: {
      fontSize: 11,
      opacity: .6
    }
  })), open && /*#__PURE__*/React.createElement("div", {
    style: {
      paddingLeft: 12
    }
  }, n.children.map((c, i) => /*#__PURE__*/React.createElement(NavItem, {
    key: i,
    n: c,
    active: active,
    onNavigate: onNavigate
  }))));
}
function Sidebar({
  active,
  onNavigate,
  collapsed,
  nav,
  brand,
  brandHref
}) {
  const D = window.ITF_DATA;
  return /*#__PURE__*/React.createElement("aside", {
    style: {
      width: collapsed ? 0 : 250,
      flexShrink: 0,
      background: 'var(--chrome-bg)',
      color: 'var(--chrome-text)',
      overflow: 'hidden',
      transition: 'width .2s ease',
      display: 'flex',
      flexDirection: 'column'
    }
  }, /*#__PURE__*/React.createElement("a", {
    onClick: () => onNavigate(brandHref || 'dashboard'),
    style: {
      display: 'flex',
      alignItems: 'center',
      gap: 10,
      padding: '14px 16px',
      cursor: 'pointer',
      borderBottom: '1px solid rgba(255,255,255,.08)',
      color: '#fff',
      textDecoration: 'none',
      whiteSpace: 'nowrap'
    }
  }, brandHref === 'dashboard' || !brandHref ? /*#__PURE__*/React.createElement(React.Fragment, null, /*#__PURE__*/React.createElement("span", {
    style: {
      width: 30,
      height: 30,
      borderRadius: 7,
      background: 'var(--color-accent)',
      display: 'flex',
      alignItems: 'center',
      justifyContent: 'center',
      flexShrink: 0
    }
  }, /*#__PURE__*/React.createElement("i", {
    className: "fas fa-bolt",
    style: {
      color: '#fff',
      fontSize: 15
    }
  })), /*#__PURE__*/React.createElement("span", {
    style: {
      fontSize: 20,
      fontWeight: 700
    }
  }, D.company)) : /*#__PURE__*/React.createElement("span", {
    style: {
      fontSize: 15,
      fontWeight: 600
    }
  }, /*#__PURE__*/React.createElement("i", {
    className: "fas fa-arrow-left",
    style: {
      marginRight: 8,
      opacity: .7
    }
  }), "Back | ", /*#__PURE__*/React.createElement("strong", null, brand))), /*#__PURE__*/React.createElement("nav", {
    style: {
      padding: '12px 10px',
      overflowY: 'auto',
      flex: 1
    }
  }, (nav || D.nav).map((n, i) => {
    if (n.type === 'header') {
      return /*#__PURE__*/React.createElement("div", {
        key: i,
        style: {
          fontSize: 11,
          fontWeight: 700,
          letterSpacing: '.04em',
          textTransform: 'uppercase',
          color: 'rgba(255,255,255,.4)',
          padding: '16px 12px 6px'
        }
      }, n.label);
    }
    if (n.type === 'divider') {
      return /*#__PURE__*/React.createElement("div", {
        key: i,
        style: {
          height: 1,
          background: 'rgba(255,255,255,.08)',
          margin: '10px 12px'
        }
      });
    }
    if (n.type === 'group') {
      return /*#__PURE__*/React.createElement(NavGroup, {
        key: i,
        n: n,
        active: active,
        onNavigate: onNavigate
      });
    }
    return /*#__PURE__*/React.createElement(NavItem, {
      key: i,
      n: n,
      active: active,
      onNavigate: onNavigate
    });
  })));
}
function TopNav({
  onToggle,
  onLogout,
  onNavigate
}) {
  const D = window.ITF_DATA;
  const [menu, setMenu] = useState(false);
  return /*#__PURE__*/React.createElement("nav", {
    style: {
      height: 'var(--navbar-height)',
      flexShrink: 0,
      background: 'var(--chrome-bg)',
      display: 'flex',
      alignItems: 'center',
      padding: '0 14px',
      gap: 14,
      color: 'var(--chrome-text)',
      position: 'relative',
      zIndex: 20
    }
  }, /*#__PURE__*/React.createElement("button", {
    onClick: onToggle,
    style: {
      background: 'none',
      border: 'none',
      color: 'rgba(255,255,255,.85)',
      fontSize: 17,
      cursor: 'pointer',
      padding: 6
    }
  }, /*#__PURE__*/React.createElement("i", {
    className: "fas fa-bars"
  })), /*#__PURE__*/React.createElement("div", {
    style: {
      flex: 1
    }
  }), /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      alignItems: 'center',
      background: 'rgba(255,255,255,.1)',
      borderRadius: 6,
      padding: '5px 10px',
      gap: 8,
      width: 230,
      maxWidth: '34vw'
    }
  }, /*#__PURE__*/React.createElement("i", {
    className: "fas fa-search",
    style: {
      fontSize: 12,
      color: 'rgba(255,255,255,.6)'
    }
  }), /*#__PURE__*/React.createElement("input", {
    placeholder: "Search everywhere",
    style: {
      background: 'none',
      border: 'none',
      outline: 'none',
      color: '#fff',
      fontSize: 13,
      width: '100%'
    }
  })), /*#__PURE__*/React.createElement("button", {
    style: {
      background: 'none',
      border: 'none',
      color: 'rgba(255,255,255,.85)',
      fontSize: 16,
      cursor: 'pointer',
      position: 'relative',
      padding: 6
    }
  }, /*#__PURE__*/React.createElement("i", {
    className: "fas fa-bell"
  }), /*#__PURE__*/React.createElement("span", {
    style: {
      position: 'absolute',
      top: 0,
      right: 0,
      background: 'var(--slate-50)',
      color: 'var(--slate-900)',
      fontSize: 10,
      fontWeight: 700,
      borderRadius: 8,
      padding: '0 5px'
    }
  }, "5")), /*#__PURE__*/React.createElement("button", {
    onClick: () => onNavigate && onNavigate('admin'),
    title: "Administration",
    style: {
      background: 'none',
      border: 'none',
      color: 'rgba(255,255,255,.75)',
      fontSize: 15,
      cursor: 'pointer',
      padding: 6
    }
  }, /*#__PURE__*/React.createElement("i", {
    className: "fas fa-cog"
  })), /*#__PURE__*/React.createElement("div", {
    style: {
      position: 'relative'
    }
  }, /*#__PURE__*/React.createElement("button", {
    onClick: () => setMenu(m => !m),
    style: {
      display: 'flex',
      alignItems: 'center',
      gap: 8,
      background: 'none',
      border: 'none',
      color: '#fff',
      cursor: 'pointer',
      fontSize: 14
    }
  }, /*#__PURE__*/React.createElement("i", {
    className: "fas fa-user-circle",
    style: {
      fontSize: 18
    }
  }), /*#__PURE__*/React.createElement("span", null, D.user.name), /*#__PURE__*/React.createElement("i", {
    className: "fas fa-caret-down",
    style: {
      fontSize: 11,
      opacity: .7
    }
  })), menu && /*#__PURE__*/React.createElement("div", {
    style: {
      position: 'absolute',
      right: 0,
      top: '100%',
      marginTop: 8,
      background: 'var(--color-surface)',
      color: 'var(--color-text)',
      borderRadius: 10,
      boxShadow: 'var(--shadow-hover)',
      width: 220,
      overflow: 'hidden',
      border: '1px solid var(--color-border)'
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      background: 'var(--chrome-bg)',
      color: '#fff',
      padding: '16px',
      textAlign: 'center'
    }
  }, /*#__PURE__*/React.createElement("i", {
    className: "fas fa-user-circle",
    style: {
      fontSize: 40
    }
  }), /*#__PURE__*/React.createElement("div", {
    style: {
      marginTop: 6,
      fontWeight: 600
    }
  }, D.user.name), /*#__PURE__*/React.createElement("div", {
    style: {
      fontSize: 12,
      opacity: .7
    }
  }, D.user.role)), /*#__PURE__*/React.createElement("div", {
    style: {
      padding: 8,
      display: 'flex',
      flexDirection: 'column',
      gap: 4
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      padding: '7px 10px',
      borderRadius: 6,
      fontSize: 13,
      cursor: 'pointer'
    }
  }, /*#__PURE__*/React.createElement("i", {
    className: "fas fa-user-cog fa-fw",
    style: {
      marginRight: 8,
      color: 'var(--color-text-muted)'
    }
  }), "Account"), /*#__PURE__*/React.createElement("div", {
    onClick: onLogout,
    style: {
      padding: '7px 10px',
      borderRadius: 6,
      fontSize: 13,
      cursor: 'pointer',
      color: 'var(--danger)'
    }
  }, /*#__PURE__*/React.createElement("i", {
    className: "fas fa-sign-out-alt fa-fw",
    style: {
      marginRight: 8
    }
  }), "Logout")))));
}
function Shell({
  active,
  onNavigate,
  onLogout,
  children,
  nav,
  brand,
  brandHref
}) {
  const [collapsed, setCollapsed] = useState(false);
  return /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      height: '100vh',
      overflow: 'hidden',
      fontFamily: 'var(--font-sans)',
      fontSize: 'var(--text-sm)'
    }
  }, /*#__PURE__*/React.createElement(Sidebar, {
    active: active,
    onNavigate: onNavigate,
    collapsed: collapsed,
    nav: nav,
    brand: brand,
    brandHref: brandHref
  }), /*#__PURE__*/React.createElement("div", {
    style: {
      flex: 1,
      display: 'flex',
      flexDirection: 'column',
      minWidth: 0
    }
  }, /*#__PURE__*/React.createElement(TopNav, {
    onToggle: () => setCollapsed(c => !c),
    onLogout: onLogout,
    onNavigate: onNavigate
  }), /*#__PURE__*/React.createElement("main", {
    style: {
      flex: 1,
      overflowY: 'auto',
      background: 'var(--color-surface-alt)',
      padding: '18px 22px'
    }
  }, children)));
}
Object.assign(window, {
  Shell
});
})(); } catch (e) { __ds_ns.__errors.push({ path: "ui_kits/itflow/Shell.jsx", error: String((e && e.message) || e) }); }

// ui_kits/itflow/TicketDetail.jsx
try { (() => {
/* Ticket detail — conversation thread + reply composer, with a sidebar of
   ticket fields, client info and a time-entry card. */
function TicketDetail({
  onNavigate
}) {
  const D = window.ITF_DATA;
  const t = D.tickets[0];
  const {
    Card,
    Button,
    Badge,
    PriorityDot,
    Avatar,
    Select
  } = window.ITFlow_b1b893;
  const [tab, setTab] = React.useState('all');
  return /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      alignItems: 'center',
      gap: 12,
      marginBottom: 14
    }
  }, /*#__PURE__*/React.createElement("a", {
    onClick: () => onNavigate('tickets'),
    style: {
      cursor: 'pointer',
      color: 'var(--color-accent)',
      fontSize: 13
    }
  }, /*#__PURE__*/React.createElement("i", {
    className: "fas fa-arrow-left",
    style: {
      marginRight: 6
    }
  }), "Tickets"), /*#__PURE__*/React.createElement("span", {
    style: {
      color: 'var(--color-text-muted)'
    }
  }, "/"), /*#__PURE__*/React.createElement("span", {
    style: {
      fontFamily: 'var(--font-mono)',
      color: 'var(--color-text-muted)'
    }
  }, "#", t.id)), /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      alignItems: 'flex-start',
      justifyContent: 'space-between',
      gap: 16,
      marginBottom: 16,
      flexWrap: 'wrap'
    }
  }, /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement("h1", {
    style: {
      margin: '0 0 6px',
      fontSize: 22,
      fontWeight: 700,
      color: 'var(--color-text)'
    }
  }, t.subject), /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      alignItems: 'center',
      gap: 12,
      fontSize: 13,
      color: 'var(--color-text-muted)'
    }
  }, /*#__PURE__*/React.createElement("span", null, /*#__PURE__*/React.createElement("i", {
    className: "fas fa-building",
    style: {
      marginRight: 5
    }
  }), t.client), /*#__PURE__*/React.createElement("span", null, /*#__PURE__*/React.createElement("i", {
    className: "fas fa-user",
    style: {
      marginRight: 5
    }
  }), t.contact), /*#__PURE__*/React.createElement("span", null, /*#__PURE__*/React.createElement("i", {
    className: "fas fa-layer-group",
    style: {
      marginRight: 5
    }
  }), t.board))), /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      gap: 8
    }
  }, /*#__PURE__*/React.createElement(Button, {
    variant: "secondary",
    icon: "check"
  }, "Resolve"), /*#__PURE__*/React.createElement(Button, {
    variant: "secondary",
    icon: "ellipsis-v"
  }))), /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'grid',
      gridTemplateColumns: '1fr 320px',
      gap: 16,
      alignItems: 'start'
    }
  }, /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement(Card, {
    noBody: true
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      gap: 4,
      padding: '4px 12px 0',
      borderBottom: '1px solid var(--color-border)'
    }
  }, [['all', 'All Comments'], ['client', 'Client'], ['internal', 'Internal']].map(([id, label]) => /*#__PURE__*/React.createElement("button", {
    key: id,
    onClick: () => setTab(id),
    style: {
      border: 'none',
      background: 'none',
      cursor: 'pointer',
      padding: '10px 12px',
      fontSize: 13,
      fontWeight: 500,
      color: tab === id ? 'var(--color-accent)' : 'var(--color-text-muted)',
      borderBottom: `2px solid ${tab === id ? 'var(--color-accent)' : 'transparent'}`,
      marginBottom: -1
    }
  }, label))), /*#__PURE__*/React.createElement("div", {
    style: {
      padding: 16,
      display: 'flex',
      flexDirection: 'column',
      gap: 14
    }
  }, D.conversation.filter(c => tab === 'all' || (tab === 'internal' ? c.internal : !c.internal)).map((c, i) => /*#__PURE__*/React.createElement("div", {
    key: i,
    style: {
      display: 'flex',
      gap: 12
    }
  }, /*#__PURE__*/React.createElement(Avatar, {
    name: c.who,
    size: 36
  }), /*#__PURE__*/React.createElement("div", {
    style: {
      flex: 1,
      background: c.internal ? 'rgba(255,193,7,.10)' : 'var(--color-surface-alt)',
      border: c.internal ? '1px solid rgba(255,193,7,.35)' : '1px solid var(--color-border-soft)',
      borderRadius: 10,
      padding: '10px 13px'
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      alignItems: 'center',
      gap: 8,
      marginBottom: 5,
      fontSize: 13
    }
  }, /*#__PURE__*/React.createElement("strong", null, c.who), /*#__PURE__*/React.createElement("span", {
    style: {
      fontSize: 11,
      color: 'var(--color-text-muted)'
    }
  }, c.role), c.internal && /*#__PURE__*/React.createElement(Badge, {
    soft: true,
    color: "warning",
    style: {
      fontSize: '.65rem'
    }
  }, "Internal"), /*#__PURE__*/React.createElement("span", {
    style: {
      marginLeft: 'auto',
      fontSize: 11,
      color: 'var(--color-text-muted)'
    }
  }, c.when)), /*#__PURE__*/React.createElement("div", {
    style: {
      fontSize: 13,
      lineHeight: 1.5,
      color: 'var(--color-text)'
    }
  }, c.body)))))), /*#__PURE__*/React.createElement("div", {
    style: {
      marginTop: 16
    }
  }, /*#__PURE__*/React.createElement(Card, {
    title: "Reply",
    icon: "reply"
  }, /*#__PURE__*/React.createElement("textarea", {
    placeholder: "Type your reply\u2026",
    rows: 4,
    style: {
      width: '100%',
      boxSizing: 'border-box',
      resize: 'vertical',
      padding: 12,
      fontFamily: 'var(--font-sans)',
      fontSize: 13,
      color: 'var(--color-text)',
      background: 'var(--color-surface)',
      border: '1px solid var(--color-border)',
      borderRadius: 'var(--input-radius)',
      outline: 'none'
    }
  }), /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      alignItems: 'center',
      gap: 8,
      marginTop: 12
    }
  }, /*#__PURE__*/React.createElement(Button, {
    icon: "paper-plane"
  }, "Send Reply"), /*#__PURE__*/React.createElement(Button, {
    variant: "secondary",
    icon: "lock"
  }, "Internal Note"), /*#__PURE__*/React.createElement("span", {
    style: {
      marginLeft: 'auto',
      fontSize: 12,
      color: 'var(--color-text-muted)'
    }
  }, /*#__PURE__*/React.createElement("i", {
    className: "fas fa-paperclip",
    style: {
      marginRight: 5
    }
  }), "Attach"))))), /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      flexDirection: 'column',
      gap: 16
    }
  }, /*#__PURE__*/React.createElement(Card, {
    title: "Details",
    icon: "info-circle"
  }, /*#__PURE__*/React.createElement(Field, {
    label: "Status"
  }, /*#__PURE__*/React.createElement(Badge, {
    customColor: t.statusColor
  }, t.status)), /*#__PURE__*/React.createElement(Field, {
    label: "Priority"
  }, /*#__PURE__*/React.createElement(PriorityDot, {
    priority: t.priority,
    label: true
  })), /*#__PURE__*/React.createElement(Field, {
    label: "Assigned"
  }, /*#__PURE__*/React.createElement("span", {
    style: {
      display: 'inline-flex',
      alignItems: 'center',
      gap: 7
    }
  }, /*#__PURE__*/React.createElement(Avatar, {
    name: t.assignee,
    size: 22
  }), t.assignee)), /*#__PURE__*/React.createElement(Field, {
    label: "Board"
  }, t.board), /*#__PURE__*/React.createElement(Field, {
    label: "Created"
  }, "Today, 9:02 AM"), /*#__PURE__*/React.createElement(Field, {
    label: "SLA"
  }, /*#__PURE__*/React.createElement("span", {
    style: {
      color: 'var(--success)'
    }
  }, /*#__PURE__*/React.createElement("i", {
    className: "fas fa-check-circle",
    style: {
      marginRight: 5
    }
  }), "On track"))), /*#__PURE__*/React.createElement(Card, {
    title: "Time Entry",
    icon: "stopwatch",
    style: {
      background: 'rgba(13,148,136,.05)'
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      fontFamily: 'var(--font-mono)',
      fontSize: 26,
      fontWeight: 700,
      color: 'var(--color-accent)'
    }
  }, "00:42:18"), /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      gap: 8,
      marginTop: 12
    }
  }, /*#__PURE__*/React.createElement(Button, {
    size: "sm",
    icon: "pause",
    variant: "secondary"
  }, "Pause"), /*#__PURE__*/React.createElement(Button, {
    size: "sm",
    icon: "stop"
  }, "Stop & Log"))), /*#__PURE__*/React.createElement(Card, {
    title: "Client",
    icon: "building"
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      fontWeight: 600,
      marginBottom: 4
    }
  }, t.client), /*#__PURE__*/React.createElement("div", {
    style: {
      fontSize: 13,
      color: 'var(--color-text-muted)',
      lineHeight: 1.6
    }
  }, /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement("i", {
    className: "fas fa-user fa-fw",
    style: {
      marginRight: 6
    }
  }), t.contact), /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement("i", {
    className: "fas fa-phone fa-fw",
    style: {
      marginRight: 6
    }
  }), "(503) 555-0188"), /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement("i", {
    className: "fas fa-ticket-alt fa-fw",
    style: {
      marginRight: 6
    }
  }), "6 open tickets"))))));
}
function Field({
  label,
  children
}) {
  return /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      alignItems: 'center',
      justifyContent: 'space-between',
      padding: '7px 0',
      borderBottom: '1px solid var(--color-border-soft)',
      fontSize: 13
    }
  }, /*#__PURE__*/React.createElement("span", {
    style: {
      color: 'var(--color-text-muted)'
    }
  }, label), /*#__PURE__*/React.createElement("span", null, children));
}
Object.assign(window, {
  TicketDetail
});
})(); } catch (e) { __ds_ns.__errors.push({ path: "ui_kits/itflow/TicketDetail.jsx", error: String((e && e.message) || e) }); }

// ui_kits/itflow/Tickets.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
/* Tickets list — the redesigned centerpiece: stat boxes, pill filter bar,
   ticket table grouped by board with inline status/priority pills. */
const {
  useState: useTicketState
} = React;
function Tickets({
  onNavigate
}) {
  const D = window.ITF_DATA;
  const {
    StatBox,
    Card,
    Button,
    Input,
    Select,
    Badge,
    PriorityDot,
    Avatar,
    IconButton
  } = window.ITFlow_b1b893;
  const [statFilter, setStatFilter] = useTicketState('all');
  const [query, setQuery] = useTicketState('');
  const stats = [{
    id: 'all',
    label: 'All Tickets',
    value: 49,
    icon: 'list',
    color: 'var(--stat-slate)'
  }, {
    id: 'unassigned',
    label: 'Unassigned',
    value: 2,
    icon: 'user-slash',
    color: 'var(--stat-amber)'
  }, {
    id: 'open',
    label: 'Unresolved',
    value: 42,
    icon: 'exclamation-circle',
    color: 'var(--stat-blue)'
  }, {
    id: 'due',
    label: 'Due Today',
    value: 5,
    icon: 'clock',
    color: 'var(--stat-orange)'
  }, {
    id: 'overdue',
    label: 'Overdue',
    value: 2,
    icon: 'fire',
    color: 'var(--stat-red)'
  }, {
    id: 'onsite',
    label: 'On-Site Open',
    value: 1,
    icon: 'map-marker-alt',
    color: 'var(--stat-violet)'
  }];
  let rows = D.tickets;
  if (statFilter === 'unassigned') rows = rows.filter(t => !t.assignee);else if (statFilter === 'overdue') rows = rows.filter(t => t.overdue);else if (statFilter === 'onsite') rows = rows.filter(t => t.onsite);
  if (query) rows = rows.filter(t => (t.subject + t.client + t.id).toLowerCase().includes(query.toLowerCase()));
  const boards = [...new Set(rows.map(t => t.board))];
  return /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'grid',
      gridTemplateColumns: 'repeat(6, 1fr)',
      gap: 10,
      marginBottom: 16
    }
  }, stats.map(s => /*#__PURE__*/React.createElement(StatBox, _extends({
    key: s.id
  }, s, {
    active: statFilter === s.id,
    href: "#",
    onClick: e => {
      e.preventDefault();
      setStatFilter(s.id);
    }
  })))), /*#__PURE__*/React.createElement(Card, {
    noBody: true
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      alignItems: 'center',
      justifyContent: 'space-between',
      padding: '12px 16px',
      borderBottom: '1px solid var(--color-border-soft)'
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      alignItems: 'center',
      gap: 10,
      fontWeight: 600
    }
  }, /*#__PURE__*/React.createElement("i", {
    className: "fas fa-life-ring",
    style: {
      color: 'var(--color-text-muted)'
    }
  }), "Tickets", /*#__PURE__*/React.createElement(Badge, {
    soft: true,
    color: "accent"
  }, rows.length, " shown")), /*#__PURE__*/React.createElement(Button, {
    icon: "plus"
  }, "New Ticket")), /*#__PURE__*/React.createElement("div", {
    style: {
      padding: 14
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      gap: 10,
      flexWrap: 'wrap',
      alignItems: 'center',
      background: 'var(--color-surface-alt)',
      border: '1px solid var(--color-border)',
      borderRadius: 'var(--card-radius)',
      padding: 12
    }
  }, /*#__PURE__*/React.createElement(Select, {
    pill: true,
    options: ['Network', 'Hardware', 'Software'],
    placeholder: "All Boards",
    containerStyle: {
      minWidth: 140
    }
  }), /*#__PURE__*/React.createElement(Select, {
    pill: true,
    options: ['New', 'In Progress', 'Waiting on Customer', 'Resolved'],
    placeholder: "Status",
    containerStyle: {
      minWidth: 150
    }
  }), /*#__PURE__*/React.createElement(Select, {
    pill: true,
    options: ['High', 'Medium', 'Low'],
    placeholder: "All Priorities",
    containerStyle: {
      minWidth: 140
    }
  }), /*#__PURE__*/React.createElement(Input, {
    pill: true,
    button: "search",
    placeholder: "Search tickets...",
    value: query,
    onChange: e => setQuery(e.target.value),
    containerStyle: {
      flex: 1,
      minWidth: 200
    }
  }), /*#__PURE__*/React.createElement(Button, {
    variant: "secondary",
    icon: "undo",
    onClick: () => {
      setStatFilter('all');
      setQuery('');
    }
  }, "Reset"))), /*#__PURE__*/React.createElement("table", {
    style: {
      width: '100%',
      borderCollapse: 'collapse',
      fontSize: 13
    }
  }, /*#__PURE__*/React.createElement("thead", null, /*#__PURE__*/React.createElement("tr", {
    style: {
      textAlign: 'left',
      color: 'var(--color-text-muted)',
      fontSize: 11,
      textTransform: 'uppercase',
      letterSpacing: '.03em'
    }
  }, /*#__PURE__*/React.createElement("th", {
    style: {
      padding: '8px 16px',
      fontWeight: 600
    }
  }, "#"), /*#__PURE__*/React.createElement("th", {
    style: {
      padding: '8px',
      fontWeight: 600
    }
  }, "Subject"), /*#__PURE__*/React.createElement("th", {
    style: {
      padding: '8px',
      fontWeight: 600
    }
  }, "Client"), /*#__PURE__*/React.createElement("th", {
    style: {
      padding: '8px',
      fontWeight: 600
    }
  }, "Priority"), /*#__PURE__*/React.createElement("th", {
    style: {
      padding: '8px',
      fontWeight: 600
    }
  }, "Status"), /*#__PURE__*/React.createElement("th", {
    style: {
      padding: '8px',
      fontWeight: 600
    }
  }, "Assigned"), /*#__PURE__*/React.createElement("th", {
    style: {
      padding: '8px 16px',
      fontWeight: 600,
      textAlign: 'right'
    }
  }, "Updated"))), /*#__PURE__*/React.createElement("tbody", null, boards.map(b => /*#__PURE__*/React.createElement(React.Fragment, {
    key: b
  }, /*#__PURE__*/React.createElement("tr", {
    style: {
      background: 'var(--color-surface-alt)'
    }
  }, /*#__PURE__*/React.createElement("td", {
    colSpan: 7,
    style: {
      padding: '7px 16px',
      fontSize: 12,
      fontWeight: 700,
      color: 'var(--color-text-muted)',
      textTransform: 'uppercase',
      letterSpacing: '.03em'
    }
  }, /*#__PURE__*/React.createElement("i", {
    className: "fas fa-layer-group",
    style: {
      marginRight: 7
    }
  }), b, /*#__PURE__*/React.createElement("span", {
    style: {
      marginLeft: 8,
      fontWeight: 400
    }
  }, "(", rows.filter(t => t.board === b).length, ")"))), rows.filter(t => t.board === b).map(t => /*#__PURE__*/React.createElement("tr", {
    key: t.id,
    onClick: () => onNavigate('ticket'),
    style: {
      borderTop: '1px solid var(--color-border-soft)',
      cursor: 'pointer'
    },
    onMouseEnter: e => e.currentTarget.style.background = 'var(--color-accent-soft)',
    onMouseLeave: e => e.currentTarget.style.background = 'transparent'
  }, /*#__PURE__*/React.createElement("td", {
    style: {
      padding: '11px 16px',
      fontFamily: 'var(--font-mono)',
      color: 'var(--color-text-muted)'
    }
  }, "#", t.id), /*#__PURE__*/React.createElement("td", {
    style: {
      padding: '11px 8px',
      fontWeight: 500,
      maxWidth: 320
    }
  }, t.subject, t.onsite && /*#__PURE__*/React.createElement(Badge, {
    soft: true,
    customColor: "var(--stat-violet)",
    style: {
      marginLeft: 8,
      fontSize: '.7rem'
    }
  }, "On-Site"), t.overdue && /*#__PURE__*/React.createElement(Badge, {
    soft: true,
    color: "danger",
    style: {
      marginLeft: 6,
      fontSize: '.7rem'
    }
  }, "Overdue")), /*#__PURE__*/React.createElement("td", {
    style: {
      padding: '11px 8px',
      color: 'var(--color-text-muted)'
    }
  }, t.client), /*#__PURE__*/React.createElement("td", {
    style: {
      padding: '11px 8px'
    }
  }, /*#__PURE__*/React.createElement(PriorityDot, {
    priority: t.priority,
    label: true
  })), /*#__PURE__*/React.createElement("td", {
    style: {
      padding: '11px 8px'
    }
  }, /*#__PURE__*/React.createElement(Badge, {
    customColor: t.statusColor,
    style: {
      color: t.priority === 'x' ? '' : undefined
    }
  }, t.status)), /*#__PURE__*/React.createElement("td", {
    style: {
      padding: '11px 8px'
    }
  }, t.assignee ? /*#__PURE__*/React.createElement("span", {
    style: {
      display: 'inline-flex',
      alignItems: 'center',
      gap: 7
    }
  }, /*#__PURE__*/React.createElement(Avatar, {
    name: t.assignee,
    size: 24
  }), t.assignee.split(' ')[0]) : /*#__PURE__*/React.createElement("span", {
    style: {
      color: 'var(--stat-amber)'
    }
  }, /*#__PURE__*/React.createElement("i", {
    className: "fas fa-user-slash",
    style: {
      marginRight: 5
    }
  }), "Unassigned")), /*#__PURE__*/React.createElement("td", {
    style: {
      padding: '11px 16px',
      textAlign: 'right',
      color: 'var(--color-text-muted)'
    }
  }, t.updated))))))), rows.length === 0 && /*#__PURE__*/React.createElement("div", {
    style: {
      padding: 40,
      textAlign: 'center',
      color: 'var(--color-text-muted)'
    }
  }, "No tickets match your filters.")));
}
Object.assign(window, {
  Tickets
});
})(); } catch (e) { __ds_ns.__errors.push({ path: "ui_kits/itflow/Tickets.jsx", error: String((e && e.message) || e) }); }

// ui_kits/itflow/app.jsx
try { (() => {
/* Root app: login gate + simple screen routing across the shell. */
const {
  useState: useAppState
} = React;
function Placeholder({
  id
}) {
  const D = window.ITF_DATA;
  const item = D.nav.find(n => n.id === id);
  const {
    Card
  } = window.ITFlow_b1b893;
  return /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement("h1", {
    style: {
      margin: '0 0 16px',
      fontSize: 22,
      fontWeight: 700,
      color: 'var(--color-text)'
    }
  }, item ? item.label : id), /*#__PURE__*/React.createElement(Card, null, /*#__PURE__*/React.createElement("div", {
    style: {
      textAlign: 'center',
      padding: '50px 20px',
      color: 'var(--color-text-muted)'
    }
  }, /*#__PURE__*/React.createElement("i", {
    className: `fas fa-${item ? item.icon : 'cube'}`,
    style: {
      fontSize: 40,
      color: 'var(--color-border)'
    }
  }), /*#__PURE__*/React.createElement("p", {
    style: {
      marginTop: 14,
      fontSize: 14
    }
  }, "The ", /*#__PURE__*/React.createElement("strong", null, item ? item.label : id), " module isn't part of this UI-kit demo."), /*#__PURE__*/React.createElement("p", {
    style: {
      fontSize: 13
    }
  }, "Explore ", /*#__PURE__*/React.createElement("strong", null, "Dashboard"), ", ", /*#__PURE__*/React.createElement("strong", null, "Tickets"), " and ", /*#__PURE__*/React.createElement("strong", null, "Clients"), " for the interactive screens."))));
}
function App() {
  const [authed, setAuthed] = useAppState(false);
  const [screen, setScreen] = useAppState('dashboard');
  if (!authed) return /*#__PURE__*/React.createElement(Login, {
    onLogin: () => {
      setAuthed(true);
      setScreen('dashboard');
    }
  });

  // Determine if we're in admin or agent context
  const adminScreens = ['admin', 'settings_company', 'settings_localization', 'settings_theme', 'settings_security', 'settings_mail', 'settings_notification', 'settings_default', 'settings_invoice', 'settings_quote', 'settings_ticket', 'settings_module', 'settings_webhooks', 'settings_integrations', 'settings_calendar_sync', 'users', 'roles', 'api_keys', 'tag', 'category', 'custom_link', 'ai_provider', 'tax', 'payment_method', 'payment_provider', 'ticket_status', 'labor_type', 'ticket_automation', 'contract_template', 'project_template', 'onboarding_template', 'ticket_template', 'canned_responses', 'worksheet_template', 'document_template', 'cron', 'mail_queue', 'audit_log', 'app_log', 'backup', 'credential_restore', 'debug', 'update'];
  const isAdmin = adminScreens.includes(screen);
  const D = window.ITF_DATA;

  // Find active admin nav id
  const adminNavActive = isAdmin ? screen : 'settings_company';
  if (isAdmin) {
    let view;
    if (screen === 'settings_company' || screen === 'admin') {
      view = /*#__PURE__*/React.createElement(AdminSettings, {
        onNavigate: setScreen
      });
    } else {
      view = /*#__PURE__*/React.createElement(AdminPlaceholder, {
        id: screen,
        onNavigate: setScreen
      });
    }
    return /*#__PURE__*/React.createElement(Shell, {
      active: adminNavActive,
      onNavigate: setScreen,
      onLogout: () => setAuthed(false),
      nav: D.adminNav,
      brand: "Administration",
      brandHref: "dashboard"
    }, view);
  }
  let view;
  if (screen === 'dashboard') view = /*#__PURE__*/React.createElement(Dashboard, {
    onNavigate: setScreen
  });else if (screen === 'tickets') view = /*#__PURE__*/React.createElement(Tickets, {
    onNavigate: setScreen
  });else if (screen === 'ticket') view = /*#__PURE__*/React.createElement(TicketDetail, {
    onNavigate: setScreen
  });else if (screen === 'clients') view = /*#__PURE__*/React.createElement(Clients, {
    onNavigate: setScreen
  });else view = /*#__PURE__*/React.createElement(Placeholder, {
    id: screen
  });
  const activeNav = screen === 'ticket' ? 'tickets' : screen;
  return /*#__PURE__*/React.createElement(Shell, {
    active: activeNav,
    onNavigate: setScreen,
    onLogout: () => setAuthed(false)
  }, view);
}
ReactDOM.createRoot(document.getElementById('root')).render(/*#__PURE__*/React.createElement(App, null));
})(); } catch (e) { __ds_ns.__errors.push({ path: "ui_kits/itflow/app.jsx", error: String((e && e.message) || e) }); }

// ui_kits/itflow/data.js
try { (() => {
/* Fake data for the ITFlow UI kit — representative MSP records. */
window.ITF_DATA = {
  // Matches $session_company_name from ITFlow admin config
  // (Admin → Settings → Company Name). Change this to match your instance.
  company: 'Foley IT',
  user: {
    name: 'Jane Quinn',
    role: 'Administrator'
  },
  // Agent sidebar — mirrors agent/includes/side_nav.php exactly
  nav: [{
    type: 'item',
    id: 'dashboard',
    label: 'Dashboard',
    icon: 'tachometer-alt'
  }, {
    type: 'item',
    id: 'alerts',
    label: 'Alerts',
    icon: 'bell',
    badge: 4,
    badgeColor: 'var(--danger)'
  }, {
    type: 'item',
    id: 'clients',
    label: 'Clients',
    icon: 'users',
    badge: 38
  }, {
    type: 'header',
    label: 'Support'
  }, {
    type: 'item',
    id: 'tickets',
    label: 'Tickets',
    icon: 'life-ring',
    badge: 42
  }, {
    type: 'item',
    id: 'recurring',
    label: 'Recurring Tickets',
    icon: 'redo-alt'
  }, {
    type: 'item',
    id: 'projects',
    label: 'Projects',
    icon: 'project-diagram',
    badge: 6
  }, {
    type: 'item',
    id: 'calendar',
    label: 'Calendar',
    icon: 'calendar-alt'
  }, {
    type: 'item',
    id: 'kb',
    label: 'Knowledge Base',
    icon: 'book'
  }, {
    type: 'header',
    label: 'Billing'
  }, {
    type: 'item',
    id: 'quotes',
    label: 'Quotes',
    icon: 'comment-dollar',
    badge: 3
  }, {
    type: 'item',
    id: 'invoices',
    label: 'Invoices',
    icon: 'file-invoice',
    badge: 6
  }, {
    type: 'item',
    id: 'recurring_invoices',
    label: 'Recurring Invoices',
    icon: 'redo-alt'
  }, {
    type: 'item',
    id: 'revenues',
    label: 'Revenues',
    icon: 'hand-holding-usd'
  }, {
    type: 'item',
    id: 'products',
    label: 'Products',
    icon: 'box-open'
  }, {
    type: 'header',
    label: 'Finance'
  }, {
    type: 'item',
    id: 'payments',
    label: 'Payments',
    icon: 'credit-card'
  }, {
    type: 'item',
    id: 'vendors',
    label: 'Vendors',
    icon: 'building'
  }, {
    type: 'item',
    id: 'expenses',
    label: 'Expenses',
    icon: 'shopping-cart'
  }, {
    type: 'item',
    id: 'recurring_expenses',
    label: 'Recurring Expenses',
    icon: 'redo-alt'
  }, {
    type: 'item',
    id: 'accounts',
    label: 'Accounts',
    icon: 'piggy-bank'
  }, {
    type: 'item',
    id: 'transfers',
    label: 'Transfers',
    icon: 'exchange-alt'
  }, {
    type: 'item',
    id: 'trips',
    label: 'Trips',
    icon: 'route'
  }, {
    type: 'header',
    label: 'RMM'
  }, {
    type: 'item',
    id: 'rmm_dashboard',
    label: 'RMM Dashboard',
    icon: 'tachometer-alt'
  }, {
    type: 'item',
    id: 'rmm_assets',
    label: 'Assets',
    icon: 'desktop'
  }, {
    type: 'item',
    id: 'rmm_alerts',
    label: 'RMM Alerts',
    icon: 'bell'
  }, {
    type: 'item',
    id: 'rmm_scripts',
    label: 'Scripts',
    icon: 'code'
  }, {
    type: 'item',
    id: 'rmm_checks',
    label: 'Check Policies',
    icon: 'heartbeat'
  }, {
    type: 'item',
    id: 'network',
    label: 'Network',
    icon: 'network-wired'
  }, {
    type: 'header',
    label: 'Backups'
  }, {
    type: 'item',
    id: 'backups',
    label: 'Dashboard',
    icon: 'cloud-upload-alt'
  }, {
    type: 'divider'
  }, {
    type: 'item',
    id: 'client_overview',
    label: 'Client Overview',
    icon: 'users',
    arrow: true
  }, {
    type: 'item',
    id: 'reports',
    label: 'Reports',
    icon: 'chart-line',
    arrow: true
  }],
  // Admin sidebar — mirrors admin/includes/side_nav.php exactly
  adminNav: [{
    type: 'header',
    label: 'Access'
  }, {
    type: 'item',
    id: 'users',
    label: 'Users',
    icon: 'users'
  }, {
    type: 'item',
    id: 'roles',
    label: 'Roles',
    icon: 'user-shield'
  }, {
    type: 'item',
    id: 'api_keys',
    label: 'API Keys',
    icon: 'key'
  }, {
    type: 'header',
    label: 'Configuration'
  }, {
    type: 'group',
    id: 'tags_cats',
    label: 'Tags & Categories',
    icon: 'sliders-h',
    children: [{
      id: 'tag',
      label: 'Tags',
      icon: 'tags'
    }, {
      id: 'category',
      label: 'Categories',
      icon: 'list-ul'
    }, {
      id: 'custom_link',
      label: 'Custom Links',
      icon: 'external-link-alt'
    }, {
      id: 'ai_provider',
      label: 'AI Providers',
      icon: 'robot'
    }]
  }, {
    type: 'group',
    id: 'billing_cfg',
    label: 'Billing',
    icon: 'hand-holding-usd',
    children: [{
      id: 'tax',
      label: 'Taxes',
      icon: 'balance-scale'
    }, {
      id: 'payment_method',
      label: 'Payment Methods',
      icon: 'money-check-alt'
    }, {
      id: 'payment_provider',
      label: 'Payment Providers',
      icon: 'credit-card'
    }]
  }, {
    type: 'group',
    id: 'ticketing_cfg',
    label: 'Ticketing',
    icon: 'life-ring',
    children: [{
      id: 'ticket_status',
      label: 'Ticket Statuses',
      icon: 'info-circle'
    }, {
      id: 'labor_type',
      label: 'Labor Types',
      icon: 'clock'
    }, {
      id: 'ticket_automation',
      label: 'Ticket Automation',
      icon: 'robot'
    }]
  }, {
    type: 'group',
    id: 'templates_cfg',
    label: 'Templates',
    icon: 'copy',
    children: [{
      id: 'contract_template',
      label: 'Contract Templates',
      icon: 'file-contract'
    }, {
      id: 'project_template',
      label: 'Project Templates',
      icon: 'project-diagram'
    }, {
      id: 'onboarding_template',
      label: 'Onboarding Templates',
      icon: 'user-plus'
    }, {
      id: 'ticket_template',
      label: 'Ticket Templates',
      icon: 'life-ring'
    }, {
      id: 'canned_responses',
      label: 'Canned Responses',
      icon: 'comment-dots'
    }, {
      id: 'worksheet_template',
      label: 'Worksheet Templates',
      icon: 'clipboard-list'
    }, {
      id: 'document_template',
      label: 'Document Templates',
      icon: 'file-alt'
    }]
  }, {
    type: 'group',
    id: 'maintenance_cfg',
    label: 'Maintenance',
    icon: 'tools',
    children: [{
      id: 'cron',
      label: 'Cron',
      icon: 'clock'
    }, {
      id: 'mail_queue',
      label: 'Mail Queue',
      icon: 'mail-bulk'
    }, {
      id: 'audit_log',
      label: 'Audit Logs',
      icon: 'history'
    }, {
      id: 'app_log',
      label: 'App Logs',
      icon: 'history'
    }, {
      id: 'backup',
      label: 'Backup',
      icon: 'cloud-upload-alt'
    }, {
      id: 'credential_restore',
      label: 'Credential Restore',
      icon: 'key'
    }, {
      id: 'debug',
      label: 'Debug',
      icon: 'bug'
    }, {
      id: 'update',
      label: 'Update',
      icon: 'download'
    }]
  }, {
    type: 'group',
    id: 'settings_cfg',
    label: 'Settings',
    icon: 'cog',
    open: true,
    children: [{
      id: 'settings_company',
      label: 'Company Details',
      icon: 'briefcase'
    }, {
      id: 'settings_localization',
      label: 'Localization',
      icon: 'globe'
    }, {
      id: 'settings_theme',
      label: 'Theme',
      icon: 'paint-brush'
    }, {
      id: 'settings_security',
      label: 'Security',
      icon: 'shield-alt'
    }, {
      id: 'settings_mail',
      label: 'Mail',
      icon: 'envelope'
    }, {
      id: 'settings_notification',
      label: 'Notifications',
      icon: 'bell'
    }, {
      id: 'settings_default',
      label: 'Defaults',
      icon: 'cogs'
    }, {
      id: 'settings_invoice',
      label: 'Invoice',
      icon: 'file-invoice'
    }, {
      id: 'settings_quote',
      label: 'Quote',
      icon: 'comment-dollar'
    }, {
      id: 'settings_ticket',
      label: 'Ticket',
      icon: 'life-ring'
    }, {
      id: 'settings_module',
      label: 'Modules',
      icon: 'cube'
    }, {
      id: 'settings_webhooks',
      label: 'Webhooks',
      icon: 'satellite-dish'
    }, {
      id: 'settings_integrations',
      label: 'Integrations',
      icon: 'plug'
    }, {
      id: 'settings_calendar_sync',
      label: 'Calendar Sync',
      icon: 'calendar-alt'
    }]
  }],
  tickets: [{
    id: 4821,
    board: 'Network',
    subject: 'VPN tunnel dropping every few hours',
    client: 'Brightwave Dental',
    contact: 'Erin Mossback',
    priority: 'High',
    status: 'In Progress',
    statusColor: '#0D9488',
    assignee: 'Marco Diaz',
    onsite: false,
    updated: '12m ago',
    overdue: false
  }, {
    id: 4820,
    board: 'Network',
    subject: 'New switch install — 24 port PoE',
    client: 'Harbor Logistics',
    contact: 'Sam Tran',
    priority: 'Medium',
    status: 'Scheduled',
    statusColor: '#6610F2',
    assignee: 'Jane Quinn',
    onsite: true,
    updated: '1h ago',
    overdue: false
  }, {
    id: 4818,
    board: 'Hardware',
    subject: 'Laptop won\u2019t boot — blue screen on startup',
    client: 'Pinewood Realty',
    contact: 'Dana Cole',
    priority: 'High',
    status: 'Waiting on Customer',
    statusColor: '#FFC107',
    assignee: 'Priya Shah',
    onsite: false,
    updated: '3h ago',
    overdue: true
  }, {
    id: 4815,
    board: 'Hardware',
    subject: 'Printer offline in accounting',
    client: 'Brightwave Dental',
    contact: 'Erin Mossback',
    priority: 'Low',
    status: 'New',
    statusColor: '#17A2B8',
    assignee: null,
    onsite: false,
    updated: '4h ago',
    overdue: false
  }, {
    id: 4811,
    board: 'Software',
    subject: 'Office 365 license reassignment for new hire',
    client: 'Lumen Studio',
    contact: 'Theo Park',
    priority: 'Medium',
    status: 'In Progress',
    statusColor: '#0D9488',
    assignee: 'Marco Diaz',
    onsite: false,
    updated: '6h ago',
    overdue: false
  }, {
    id: 4809,
    board: 'Software',
    subject: 'QuickBooks multi-user mode error H202',
    client: 'Harbor Logistics',
    contact: 'Sam Tran',
    priority: 'High',
    status: 'New',
    statusColor: '#17A2B8',
    assignee: null,
    onsite: false,
    updated: '1d ago',
    overdue: true
  }, {
    id: 4804,
    board: 'Software',
    subject: 'Password reset — domain admin',
    client: 'Pinewood Realty',
    contact: 'Dana Cole',
    priority: 'Low',
    status: 'Resolved',
    statusColor: '#28A745',
    assignee: 'Priya Shah',
    onsite: false,
    updated: '1d ago',
    overdue: false
  }],
  clients: [{
    name: 'Brightwave Dental',
    contact: 'Erin Mossback',
    tickets: 6,
    assets: 24,
    balance: 1240,
    mrr: 850,
    tag: 'Managed'
  }, {
    name: 'Harbor Logistics',
    contact: 'Sam Tran',
    tickets: 4,
    assets: 58,
    balance: 0,
    mrr: 2100,
    tag: 'Managed'
  }, {
    name: 'Pinewood Realty',
    contact: 'Dana Cole',
    tickets: 3,
    assets: 12,
    balance: 480,
    mrr: 540,
    tag: 'Break-Fix'
  }, {
    name: 'Lumen Studio',
    contact: 'Theo Park',
    tickets: 2,
    assets: 9,
    balance: 0,
    mrr: 320,
    tag: 'Managed'
  }, {
    name: 'Cedar Grove Clinic',
    contact: 'Nina Alvarez',
    tickets: 1,
    assets: 31,
    balance: 95,
    mrr: 1180,
    tag: 'Managed'
  }, {
    name: 'Atlas Manufacturing',
    contact: 'Owen Bell',
    tickets: 0,
    assets: 76,
    balance: 0,
    mrr: 3400,
    tag: 'Managed'
  }],
  conversation: [{
    who: 'Erin Mossback',
    role: 'Contact',
    when: '9:02 AM',
    internal: false,
    body: 'The VPN keeps dropping for our remote front desk. It reconnects after a minute but it\u2019s happened maybe a dozen times since yesterday.'
  }, {
    who: 'Marco Diaz',
    role: 'Technician',
    when: '9:18 AM',
    internal: false,
    body: 'Thanks Erin — I can see the tunnel resets in the firewall logs around the top of each hour. Looks tied to the ISP\u2019s DHCP lease renewal. I\u2019m setting a static WAN reservation now and will monitor.'
  }, {
    who: 'Marco Diaz',
    role: 'Technician',
    when: '9:21 AM',
    internal: true,
    body: 'Internal: opened a ticket with the ISP (ref 88213) to confirm lease time. Will escalate to onsite if the static reservation doesn\u2019t hold overnight.'
  }]
};
})(); } catch (e) { __ds_ns.__errors.push({ path: "ui_kits/itflow/data.js", error: String((e && e.message) || e) }); }

__ds_ns.Alert = __ds_scope.Alert;

__ds_ns.Avatar = __ds_scope.Avatar;

__ds_ns.Badge = __ds_scope.Badge;

__ds_ns.Button = __ds_scope.Button;

__ds_ns.Card = __ds_scope.Card;

__ds_ns.IconButton = __ds_scope.IconButton;

__ds_ns.PriorityDot = __ds_scope.PriorityDot;

__ds_ns.SmallBox = __ds_scope.SmallBox;

__ds_ns.StatBox = __ds_scope.StatBox;

__ds_ns.Input = __ds_scope.Input;

__ds_ns.Select = __ds_scope.Select;

__ds_ns.Switch = __ds_scope.Switch;

})();
