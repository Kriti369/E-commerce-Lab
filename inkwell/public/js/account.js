// Purchase history: what was bought, when, and the download links.
(async () => {
  const $ = s => document.querySelector(s);
  const s = await Auth.load();
  if (!s.user) { location.href = 'login.html?next=account.html'; return; }
  $('#hello').textContent = `Signed in as ${s.user.name} (${s.user.email})`;

  const res = await fetch('api/orders.php', { credentials: 'same-origin' });
  if (res.status === 401) { location.href = 'login.html?next=account.html'; return; }
  const { orders } = await res.json();

  if (!orders.length) {
    $('#orders').innerHTML = '<div class="panel"><h2>No orders yet</h2><p>Books you buy will appear here with the date and a download link.</p><a class="btn" href="index.html">Browse books</a></div>';
    return;
  }
  const label = { paid: 'Paid', failed: 'Payment failed', pending: 'Processing' };
  $('#orders').innerHTML = orders.map(o => {
    const when = new Date(o.created * 1000).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
    const items = o.items.map(i => `<li><span class="grow">${esc(i.title)}</span><span>${money(i.price)}</span>
      ${i.url ? `<a class="btn small outline" href="${esc(i.url)}">Download</a>` : ''}</li>`).join('');
    const foot = o.status === 'failed' ? esc(o.note) : (o.card_last4 ? 'Paid with card ending ' + esc(o.card_last4) : '');
    return `<article class="panel order">
      <div class="order-head"><div><h2>Order ${esc(o.ref)}</h2><p class="meta">${esc(when)}</p></div>
        <span class="chip ${esc(o.status)}">${esc(label[o.status] || o.status)}</span></div>
      <ul class="order-items">${items}</ul>
      <div class="order-foot"><span class="meta">${foot}</span><strong>Total ${money(o.total)}</strong></div></article>`;
  }).join('');
})();
