// Sticker editor for the WeaponPaints site. Builds its DOM with createElement and textContent only,
// so sticker names and server data are never parsed as HTML.
(function () {
  const config = window.WP_STICKER_CONFIG || { token: '', team: 2, stickers: {} };
  const SLOT_COUNT = 5;
  const modal = document.getElementById('sticker-modal');
  if (!modal) return;

  const slotsEl = document.getElementById('sticker-slots');
  const editorEl = document.getElementById('sticker-editor');
  const msgEl = document.getElementById('sticker-msg');
  const titleEl = document.getElementById('sticker-title');

  let defindex = null;
  let slots = [];
  let active = 0;
  let searchGeneration = 0;
  let lastQuery = ''; // kept across re-renders so picking a sticker doesn't clear the search

  const emptySlot = () => ({ id: 0, schema: 0, name: '', image: '', x: 0, y: 0, wear: 0, scale: 1, rotation: 0 });

  // Numeric limits must match StickerClass::validate() on the server.
  const LIMITS = {
    x: { min: -1, max: 1, step: 0.01 },
    y: { min: -1, max: 1, step: 0.01 },
    wear: { min: 0, max: 1, step: 0.01 },
    scale: { min: 0.1, max: 5, step: 0.05 },
    rotation: { min: 0, max: 360, step: 1 },
  };

  function el(tag, props = {}, children = []) {
    const node = document.createElement(tag);
    Object.assign(node, props);
    node.append(...children);
    return node;
  }

  function renderSlots() {
    slotsEl.replaceChildren(
      ...slots.map((slot, i) => {
        const tile = el('button', {
          type: 'button',
          className: 'sticker-tile' + (i === active ? ' active' : ''),
        });
        if (slot.id) {
          tile.append(el('img', { src: slot.image, alt: '', loading: 'lazy' }));
        }
        tile.append(el('span', { textContent: slot.id ? slot.name : `Slot ${i + 1} (empty)` }));
        tile.addEventListener('click', () => {
          active = i;
          renderAll();
        });
        return tile;
      }),
    );
  }

  function numberControl(key, label) {
    const limit = LIMITS[key];
    const input = el('input', {
      type: 'number',
      min: limit.min,
      max: limit.max,
      step: limit.step,
      value: slots[active][key],
      className: 'form-control form-control-sm',
    });
    input.addEventListener('change', () => {
      const value = Math.min(limit.max, Math.max(limit.min, Number(input.value) || 0));
      slots[active][key] = value;
      input.value = value;
    });
    return el('label', { textContent: label }, [input]);
  }

  function renderEditor() {
    const slot = slots[active];
    const search = el('input', {
      type: 'search',
      placeholder: 'Search stickers',
      className: 'form-control',
      maxLength: 64,
      value: lastQuery,
      autocomplete: 'off',
    });
    const status = el('p', { className: 'muted sticker-status' });
    const results = el('div', { className: 'sticker-results' });
    const more = el('button', { type: 'button', className: 'btn btn-secondary btn-sm', textContent: 'Load more' });
    more.hidden = true;

    let page = 1;
    const generation = ++searchGeneration;

    async function load(query, nextPage) {
      const res = await fetch(
        `stickers_api.php?q=${encodeURIComponent(query)}&page=${nextPage}`,
        { credentials: 'same-origin' },
      );
      const data = await res.json().catch(() => ({}));
      if (!res.ok) throw new Error(data.error || res.statusText);
      return data;
    }

    async function show(query, nextPage) {
      try {
        const data = await load(query, nextPage);
        if (generation !== searchGeneration) return; // a newer render has replaced this one
        if (nextPage === 1) results.replaceChildren();
        status.textContent = data.total === 0
          ? 'No stickers match. Try fewer words, e.g. a player and a year.'
          : `${data.total} sticker${data.total === 1 ? '' : 's'}`;
        page = nextPage;
        for (const sticker of data.items) {
          const button = el('button', { type: 'button', className: 'sticker-result' }, [
            el('img', { src: sticker.image, alt: '', loading: 'lazy' }),
            el('span', { textContent: sticker.name.replace(/^Sticker \| /, '') }),
          ]);
          button.addEventListener('click', () => pick(sticker));
          results.append(button);
        }
        more.hidden = !data.has_more;
      } catch (err) {
        msgEl.textContent = `Search failed: ${err.message}`;
      }
    }

    let timer = null;
    search.addEventListener('input', () => {
      clearTimeout(timer);
      lastQuery = search.value;
      timer = setTimeout(() => show(search.value, 1), 200);
    });
    more.addEventListener('click', () => show(search.value, page + 1));

    const children = [
      el('h6', { textContent: `Slot ${active + 1}` }),
      search,
      status,
      results,
      more,
      el('div', { className: 'sticker-controls' }, [
        el('button', {
          type: 'button',
          className: 'btn btn-secondary btn-sm',
          textContent: 'Clear slot',
          disabled: !slot.id,
          onclick: () => {
            slots[active] = emptySlot();
            renderAll();
          },
        }),
      ]),
    ];

    if (slot.id) {
      children.push(
        el('p', { className: 'muted', textContent: `Selected: ${slot.name}` }),
        el('div', { className: 'sticker-controls' }, [
          numberControl('wear', 'Wear (0–1)'),
          numberControl('x', 'X offset (−1 to 1)'),
          numberControl('y', 'Y offset (−1 to 1)'),
          numberControl('scale', 'Scale (0.1–5)'),
          numberControl('rotation', 'Rotation (0–360)'),
        ]),
      );
    }

    editorEl.replaceChildren(...children);
    show(lastQuery, 1);
  }

  function pick(sticker) {
    slots[active] = { ...emptySlot(), id: sticker.id, name: sticker.name, image: sticker.image };
    renderAll();
  }

  function renderAll() {
    renderSlots();
    renderEditor();
  }

  function open(index) {
    defindex = index;
    const current = config.stickers[index] || [];
    slots = Array.from({ length: SLOT_COUNT }, (_, i) => ({ ...emptySlot(), ...(current[i] || {}) }));
    active = 0;
    lastQuery = '';
    msgEl.textContent = '';
    titleEl.textContent = 'Stickers';
    modal.hidden = false;
    renderAll();
  }

  function close() {
    modal.hidden = true;
    searchGeneration++; // stop any pending search from writing into a closed dialog
  }

  async function save() {
    msgEl.textContent = 'Saving…';
    const payload = {
      defindex,
      team: config.team,
      slots: slots.map(({ id, schema, x, y, wear, scale, rotation }) => ({ id, schema, x, y, wear, scale, rotation })),
    };
    try {
      const res = await fetch('stickers_api.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': config.token },
        body: JSON.stringify(payload),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok) throw new Error(data.error || res.statusText);
      config.stickers[defindex] = slots;
      msgEl.textContent = 'Saved. Reconnect to the server to see the change.';
    } catch (err) {
      msgEl.textContent = `Save failed: ${err.message}`;
    }
  }

  document.querySelectorAll('.sticker-open').forEach((button) =>
    button.addEventListener('click', () => open(Number(button.dataset.defindex))),
  );
  document.getElementById('sticker-close').addEventListener('click', close);
  document.getElementById('sticker-save').addEventListener('click', save);
  document.getElementById('sticker-clear-all').addEventListener('click', () => {
    slots = Array.from({ length: SLOT_COUNT }, emptySlot);
    active = 0;
    renderAll();
  });
  modal.addEventListener('click', (event) => {
    if (event.target === modal) close();
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && !modal.hidden) close();
  });
})();
