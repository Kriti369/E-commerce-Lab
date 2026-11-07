// Storefront: catalogue, search/filter, cart drawer.
(async () => {
  const books = await Cart.loadBooks();
  const byId = Object.fromEntries(books.map(b => [b.id, b]));
  const $ = s => document.querySelector(s);

  // hero covers + category filter
  $('#hero-stack').innerHTML = books.slice(0, 3).map(b => coverHTML(b)).join('');
  const cats = ['All categories', ...new Set(books.map(b => b.category))];
  $('#cat').innerHTML = cats.map((c, i) => `<option value="${i ? esc(c) : ''}">${esc(c)}</option>`).join('');

  function renderGrid() {
    const q = $('#q').value.trim().toLowerCase(), cat = $('#cat').value;
    const shown = books.filter(b => (!cat || b.category === cat) && (b.title + ' ' + b.author).toLowerCase().includes(q));
    $('#grid').innerHTML = shown.map(b => `
      <article class="book">
        ${coverHTML(b)}
        <h3>${esc(b.title)}</h3>
        <p class="meta">${esc(b.author)}, ${b.pages} pages</p>
        <p class="desc">${esc(b.description)}</p>
        <div class="buy"><span class="price">${money(b.price)}</span>
          <button class="btn small" data-add="${esc(b.id)}" ${Cart.has(b.id) ? 'disabled' : ''}>${Cart.has(b.id) ? 'In cart' : 'Add to cart'}</button>
        </div>
      </article>`).join('');
    $('#empty').hidden = shown.length > 0;
  }

  function renderCart() {
    const ids = Cart.get().filter(id => byId[id]);
    $('#cart-count').textContent = ids.length;
    $('#cart-items').innerHTML = ids.length ? ids.map(id => { const b = byId[id]; return `
      <li>${coverHTML(b, 'mini')}<div class="grow"><strong>${esc(b.title)}</strong><br><span class="meta">${money(b.price)}</span></div>
      <button class="linkbtn" data-remove="${esc(id)}">Remove</button></li>`; }).join('')
      : '<li>Your cart is empty. Add a book to get started.</li>';
    $('#cart-total').textContent = money(ids.reduce((s, id) => s + byId[id].price, 0));
    $('#go-checkout').toggleAttribute('hidden', !ids.length);
    renderGrid();
  }

  const open = v => document.body.classList.toggle('cart-open', v);
  $('#q').addEventListener('input', renderGrid);
  $('#cat').addEventListener('change', renderGrid);
  $('#open-cart').addEventListener('click', () => open(true));
  $('#close-cart').addEventListener('click', () => open(false));
  $('#overlay').addEventListener('click', () => open(false));
  document.addEventListener('keydown', e => { if (e.key === 'Escape') open(false); });
  document.addEventListener('cart:change', renderCart);
  document.addEventListener('click', e => {
    if (e.target.dataset.add) { Cart.add(e.target.dataset.add); open(true); }
    if (e.target.dataset.remove) Cart.remove(e.target.dataset.remove);
  });
  renderCart();
})();
