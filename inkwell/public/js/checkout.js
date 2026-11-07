// Checkout page: needs a signed-in customer, shows the summary and sends the order to api/checkout.php.
(async () => {
  const $ = s => document.querySelector(s);
  let session = await Auth.load();
  if (!session.user) { location.href = 'login.html?next=checkout.html'; return; }

  const books = await Cart.loadBooks();
  const byId = Object.fromEntries(books.map(b => [b.id, b]));
  const ids = Cart.get().filter(id => byId[id]);

  if (!ids.length) {
    $('#checkout').innerHTML = '<div class="panel"><h2>Your cart is empty</h2><p>Pick a book first, then come back to pay.</p><a class="btn" href="index.html">Browse books</a></div>';
    return;
  }
  $('#who').textContent = `Ordering as ${session.user.name} (${session.user.email})`;
  $('#summary').innerHTML = ids.map(id => `<div class="line">${coverHTML(byId[id], 'mini')}
    <div class="grow"><strong>${esc(byId[id].title)}</strong><br><span class="meta">${esc(byId[id].author)}</span></div>
    <span>${money(byId[id].price)}</span></div>`).join('');
  $('#total').textContent = money(ids.reduce((s, id) => s + byId[id].price, 0));

  const newKey = () => { const a = new Uint8Array(16); crypto.getRandomValues(a); return [...a].map(x => x.toString(16).padStart(2, '0')).join(''); };
  let idem = newKey();

  // friendlier card inputs
  $('#number').addEventListener('input', e => { e.target.value = e.target.value.replace(/\D/g, '').slice(0, 19).replace(/(.{4})/g, '$1 ').trim(); });
  $('#exp').addEventListener('input', e => {
    const v = e.target.value.replace(/\D/g, '').slice(0, 4);
    e.target.value = v.length > 2 ? v.slice(0, 2) + '/' + v.slice(2) : v;
  });

  const msg = (t, err) => { $('#msg').textContent = t; $('#msg').className = err ? 'err' : ''; };

  $('#pay-form').addEventListener('submit', async e => {
    e.preventDefault();
    const btn = $('#pay'); btn.disabled = true; msg('Processing payment. Please do not close this page...');
    try {
      const res = await fetch('api/checkout.php', {
        method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          csrf: session.csrf, idem, items: ids,
          card: { number: $('#number').value, exp: $('#exp').value, cvc: $('#cvc').value },
        }),
      });
      const data = await res.json();
      if (res.status === 401) { location.href = 'login.html?next=checkout.html'; return; }
      if (data.ok) {
        Cart.clear();
        $('#checkout').innerHTML = `<div class="panel success" style="grid-column:1/-1">
          <h2>Payment received. Thank you!</h2>
          <p>Order reference <strong>${esc(data.ref)}</strong>, total ${money(data.total)}. Your books are also saved under <a href="account.html">My orders</a>.</p>
          <ul>${data.downloads.map(d => `<li><a href="${esc(d.url)}">Download ${esc(d.title)}</a></li>`).join('')}</ul>
          <a class="btn outline" href="index.html">Keep browsing</a></div>`;
        return;
      }
      msg(data.message || 'Something went wrong.', true);
      if (data.status === 'failed') idem = newKey();     // a declined order is finished; the next try is a new order
      if (res.status === 403) { Auth.reset(); session = await Auth.load(); }
    } catch {
      msg('Could not reach the server. Check your connection and try again. You will not be charged twice.', true);
    }
    btn.disabled = false;
  });
})();
