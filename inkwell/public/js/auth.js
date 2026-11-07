// Sign in / create account form.
(() => {
  const $ = s => document.querySelector(s);
  // only allow redirects to our own pages
  const n = new URLSearchParams(location.search).get('next');
  const next = ['checkout.html', 'account.html', 'index.html'].includes(n) ? n : 'index.html';
  let mode = 'login';
  const msg = (t, err) => { $('#msg').textContent = t; $('#msg').className = err ? 'err' : ''; };

  function setMode(m) {
    mode = m;
    $('#tab-in').classList.toggle('on', m === 'login');
    $('#tab-up').classList.toggle('on', m === 'register');
    $('#name-row').hidden = m !== 'register';
    $('#pw-hint').hidden = m !== 'register';
    $('#password').autocomplete = m === 'login' ? 'current-password' : 'new-password';
    $('#submit').textContent = m === 'login' ? 'Sign in' : 'Create account';
    msg('');
  }
  $('#tab-in').addEventListener('click', () => setMode('login'));
  $('#tab-up').addEventListener('click', () => setMode('register'));
  if (location.hash === '#register') setMode('register');

  Auth.load().then(s => { if (s.user) location.href = next; });

  $('#auth-form').addEventListener('submit', async e => {
    e.preventDefault();
    const btn = $('#submit'); btn.disabled = true; msg('One moment...');
    try {
      const { data } = await Auth.post(mode, { name: $('#name').value, email: $('#email').value, password: $('#password').value });
      if (data.ok) { location.href = next; return; }
      msg(data.message || 'Something went wrong.', true);
    } catch { msg('Could not reach the server. Try again.', true); }
    btn.disabled = false;
  });
})();
