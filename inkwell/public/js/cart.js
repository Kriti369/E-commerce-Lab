// Shared helpers used by both pages: cart (saved in the browser), money, escaping, covers.
const Cart = (() => {
  const KEY = 'inkwell_cart';
  const get = () => { try { const a = JSON.parse(localStorage.getItem(KEY)); return Array.isArray(a) ? a : []; } catch { return []; } };
  const set = a => { localStorage.setItem(KEY, JSON.stringify(a)); document.dispatchEvent(new Event('cart:change')); };
  return {
    get,
    has: id => get().includes(id),
    add: id => { if (!get().includes(id)) set([...get(), id]); },   // e-books: one copy each
    remove: id => set(get().filter(x => x !== id)),
    clear: () => set([]),
    loadBooks: async () => (await fetch('data/books.json')).json(),
  };
})();

const money = n => '$' + Number(n).toFixed(2);
const esc = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const hex = c => (/^#[0-9a-f]{6}$/i.test(c) ? c : '#333333');
const coverHTML = (b, cls = '') =>
  `<div class="cover ${cls}" style="--c1:${hex(b.color1)};--c2:${hex(b.color2)}"><b>${esc(b.title)}</b><small>${esc(b.author)}</small></div>`;

// Sign-in state. The server keeps the session; this just asks it who is signed in.
const Auth = (() => {
  let cache = null;
  const load = async () => (cache ??= await (await fetch('api/auth.php', { credentials: 'same-origin' })).json());
  const reset = () => { cache = null; };
  const post = async (action, body = {}) => {
    const s = await load();
    const res = await fetch('api/auth.php', {
      method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ ...body, action, csrf: s.csrf }),
    });
    const data = await res.json();
    reset();
    return { res, data };
  };
  return { load, reset, post };
})();

// Fills the header links on every page that has <div id="nav-user">.
(async () => {
  const nav = document.getElementById('nav-user');
  if (!nav) return;
  const { user } = await Auth.load();
  nav.innerHTML = user
    ? `<a href="account.html">My orders</a><span class="who">${esc(user.name)}</span><button class="linkbtn" id="logout">Sign out</button>`
    : '<a href="login.html">Sign in</a>';
  const out = document.getElementById('logout');
  if (out) out.addEventListener('click', async () => { await Auth.post('logout'); location.href = 'index.html'; });
})();
