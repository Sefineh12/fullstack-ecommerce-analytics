const $ = sel => document.querySelector(sel)
const views = { shop, cart: cartView, orders, admin, login, notifications: notificationsView, product: productView, checkout: checkoutView, register, forgot, account, reset: resetView, privacy }
let store, csrf, user = null, unread = 0
let cart = JSON.parse(localStorage.getItem('cart') || '[]')

// Small DOM helper. Text is always added as text, never as HTML, so product names cannot inject code.
function h(tag, attrs = {}, ...kids) {
  const el = document.createElement(tag)
  for (const [key, value] of Object.entries(attrs)) {
    if (key.startsWith('on')) el.addEventListener(key.slice(2), value)
    else if (value !== false && value != null) el.setAttribute(key, value)
  }
  el.append(...kids.flat().filter(k => k != null && k !== false))
  return el
}

const priceOf = p => (p.discount_cents > 0 && p.discount_cents < p.price_cents ? p.discount_cents : p.price_cents)
const money = cents => `${(cents / 100).toFixed(2)} ${store.currency}`
const safeUrl = url => /^(https?:\/\/|\/)/.test(url)

function flash(message) {
  $('#flash').textContent = message
  setTimeout(() => ($('#flash').textContent = ''), 3000)
}

async function getJson(url) {
  const res = await fetch(url)
  if (!res.ok) throw new Error('Request failed')
  return res.json()
}

async function upload(path, data) {
  const res = await fetch('/api' + path, { method: 'POST', headers: { 'X-CSRF-Token': csrf }, body: data })
  const out = await res.json()
  if (!res.ok) throw new Error(out.error || 'Request failed')
  return out
}

async function api(path, method = 'GET', body) {
  const res = await fetch('/api' + path, {
    method,
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf || '' },
    body: body ? JSON.stringify(body) : undefined,
  })
  const data = await res.json()
  if (!res.ok) throw new Error(data.error || 'Request failed')
  return data
}

function saveCart() {
  localStorage.setItem('cart', JSON.stringify(cart))
  drawNav()
}

async function refreshMe() {
  const me = await api('/me')
  csrf = me.csrf
  user = me.user
  unread = me.unread
  $('#who').replaceChildren(...(user ? [h('a', { href: '#account', class: 'who' }, avatar(32), user.name)] : []))
  $('#auth').textContent = user ? 'Log out' : 'Log in'
  drawNav()
}

function drawNav() {
  const count = cart.reduce((n, i) => n + i.qty, 0)
  const items = [['shop', 'Home'], ['account', 'My account']]
  if (user && user.role === 'admin') items.push(['admin', 'Admin'])
  const current = location.hash.slice(1) || 'shop'
  $('#nav').replaceChildren(...items.map(([id, label]) =>
    h('a', { href: '#' + id, class: id === current ? 'active' : '' }, label)))
  $('#cartCount').textContent = count
  $('#cartCount').hidden = count === 0
  $('#bell').hidden = !user
  $('#bellCount').textContent = unread
  $('#bellCount').hidden = unread === 0
}

function addToCart(p) {
  const line = cart.find(i => i.id === p.id)
  if (line) line.qty = Math.min(line.qty + 1, p.stock)
  else cart.push({ id: p.id, name: p.name, price: priceOf(p), qty: 1, stock: p.stock })
  saveCart()
  flash(`${p.name} added to cart`)
}

let products = []
let query = ''
const filters = { maxPrice: '', inStock: false, category: '' }

function stockLabel(stock) {
  if (stock < 1) return h('p', { class: 'stock out' }, 'Out of stock')
  if (stock <= 5) return h('p', { class: 'stock low' }, `Only ${stock} left`)
  return h('p', { class: 'stock' }, 'In stock')
}

function productCard(p) {
  const sale = priceOf(p) < p.price_cents
  return h('article', { class: 'card' },
    safeUrl(p.image_url) ? h('a', { href: '#product/' + p.id }, h('img', { src: p.image_url, alt: p.name })) : null,
    sale ? h('span', { class: 'sale' }, 'SALE') : null,
    h('h2', {}, h('a', { href: '#product/' + p.id }, p.name)),
    h('p', { class: 'muted' }, [p.brand, p.category].filter(Boolean).join(' · ')),
    h('p', { class: 'price' }, money(priceOf(p)), sale ? h('s', { class: 'muted' }, ' ' + money(p.price_cents)) : null),
    stockLabel(p.stock),
    h('button', { disabled: p.stock < 1, onclick: () => addToCart(p) }, 'Add to cart'))
}

function drawGrid() {
  const grid = $('#grid')
  if (!grid) return
  const found = products.filter(p =>
    `${p.name} ${p.description}`.toLowerCase().includes(query) &&
    (!filters.inStock || p.stock > 0) &&
    (!filters.maxPrice || priceOf(p) <= filters.maxPrice * 100) &&
    (!filters.category || p.category_id === Number(filters.category)))
  grid.replaceChildren(...(found.length ? found.map(productCard) : [h('p', { class: 'muted' }, 'No products match your search.')]))
}

function hero() {
  return h('section', { class: 'hero' },
    h('img', { src: store.images.hero, alt: store.texts.heroAlt }),
    h('a', { class: 'hero-cta', href: '#shop', 'aria-label': 'Shop now',
      onclick: e => { e.preventDefault(); $('#products').scrollIntoView({ behavior: 'smooth' }) } }),
    h('p', { class: 'tagline' }, store.texts.tagline))
}

async function shop() {
  const [catalog, cats] = await Promise.all([api('/products'), api('/categories')])
  products = catalog.products
  const grid = h('div', { id: 'grid', class: 'grid' })
  const side = h('aside', { class: 'side' }, h('h3', {}, 'Categories'),
    ...[{ id: '', name: 'All' }, ...cats.categories].map(c => h('button', {
      class: String(c.id) === String(filters.category) ? 'cat on' : 'cat',
      onclick: () => { filters.category = c.id; render() } }, c.name)))
  const view = h('div', {}, hero(),
    h('h2', { id: 'products', class: 'section' }, 'Featured products'),
    h('div', { class: 'shop' }, side, h('div', {},
      h('div', { class: 'filters' },
        h('label', {}, 'Max price ', h('input', { type: 'number', min: 0, value: filters.maxPrice, style: 'width: 6rem',
          onchange: e => { filters.maxPrice = e.target.value; drawGrid() } })),
        h('label', {}, h('input', { type: 'checkbox', checked: filters.inStock,
          onchange: e => { filters.inStock = e.target.checked; drawGrid() } }), ' In stock only')),
      grid)))
  setTimeout(drawGrid)
  return view
}

const stars = n => '★'.repeat(n) + '☆'.repeat(5 - n)

function wishlistButton(p) {
  if (!user) return null
  const button = h('button', { class: 'ghost', onclick: async () => {
    try {
      const res = await api('/wishlist/' + p.id, 'POST')
      button.textContent = res.saved ? '♥ Saved to wishlist' : '♡ Save to wishlist'
    } catch (err) {
      flash(err.message)
    }
  } }, p.in_wishlist ? '♥ Saved to wishlist' : '♡ Save to wishlist')
  return button
}

function reviewsSection(p) {
  const average = p.reviews.length ? p.reviews.reduce((sum, r) => sum + r.rating, 0) / p.reviews.length : 0
  let form = h('p', { class: 'muted' }, user ? 'Only customers who bought this product can write a review.' : 'Log in to write a review.')
  if (p.can_review) {
    const rating = h('select', { 'aria-label': 'Rating' }, [5, 4, 3, 2, 1].map(n => h('option', { value: n }, `${n} stars`)))
    const comment = h('textarea', { 'aria-label': 'Your review', placeholder: 'Write your review', maxlength: 1000 })
    form = h('form', { class: 'form', onsubmit: async e => {
      e.preventDefault()
      try {
        await api(`/products/${p.id}/review`, 'POST', { rating: +rating.value, comment: comment.value })
        flash('Thank you for your review')
        render()
      } catch (err) {
        flash(err.message)
      }
    } }, rating, comment, h('button', { type: 'submit' }, 'Post Review'))
  }
  return h('section', {},
    h('h2', { class: 'section' }, 'Reviews'),
    h('p', {}, p.reviews.length ? `${stars(Math.round(average))} ${average.toFixed(1)} out of 5 (${p.reviews.length} reviews)` : 'No reviews yet.'),
    ...p.reviews.map(r => h('div', { class: 'card', style: 'margin-bottom: .75rem' },
      h('p', {}, h('strong', {}, r.name), ` ${stars(r.rating)} (${r.rating}/5) `, h('span', { class: 'muted' }, r.created_at)),
      r.comment ? h('p', {}, r.comment) : null)),
    form)
}

async function productView(id) {
  const { product: p } = await api('/products/' + encodeURIComponent(id))
  const pictures = [p.image_url, ...p.images.map(i => i.path)].filter(safeUrl)
  const main = h('img', { alt: p.name, class: 'big', src: pictures[0] || '' })
  const sale = priceOf(p) < p.price_cents
  return h('div', {}, h('section', { class: 'detail' },
    h('div', {}, pictures.length ? main : null,
      h('div', { class: 'thumbs' }, pictures.map(src => h('img', { src, alt: '', class: 'thumb', onclick: () => main.setAttribute('src', src) })))),
    h('div', {},
      h('h1', {}, p.name),
      h('p', { class: 'muted' }, [p.brand, p.category].filter(Boolean).join(' · ')),
      h('p', { class: 'price' }, money(priceOf(p)), sale ? h('s', { class: 'muted' }, ' ' + money(p.price_cents)) : null),
      stockLabel(p.stock),
      h('p', {}, p.description),
      h('button', { disabled: p.stock < 1, onclick: () => addToCart(p) }, 'Add to cart'),
      wishlistButton(p),
      h('p', {}, h('a', { href: '#shop' }, 'Back to shop')))),
    reviewsSection(p))
}

function privacy() {
  return h('section', { class: 'prose' }, h('h1', {}, 'Privacy policy'),
    ...store.privacy.map(text => h('p', {}, text)))
}

function drawFooter() {
  const c = store.contact
  const link = (href, text, external) => h('a', external ? { href, target: '_blank', rel: 'noopener' } : { href }, text)
  $('#footer').replaceChildren(
    h('div', { class: 'cols' },
      h('div', {}, h('h3', {}, 'About us'), h('p', { class: 'muted' }, store.texts.about)),
      h('div', {}, h('h3', {}, 'Contact'),
        h('p', {}, link('tel:' + c.phone, c.phone)),
        h('p', {}, link('mailto:' + c.email, c.email)),
        h('p', {}, link(c.telegram, 'Telegram', true))),
      h('div', {}, h('h3', {}, 'Find me online'),
        h('p', {}, link(c.portfolio, 'Portfolio', true)),
        h('p', {}, link(c.github, 'GitHub', true))),
      h('div', {}, h('h3', {}, 'Quick links'),
        h('p', {}, link('#shop', 'Home')),
        h('p', {}, link('#privacy', 'Privacy policy')),
        h('p', {}, link('mailto:' + c.email, 'Customer support')))),
    h('p', { class: 'copy' }, store.texts.footer))
}

function cartView() {
  if (!cart.length) return h('section', {}, h('h1', {}, 'Your cart'), h('p', { class: 'muted' }, 'Your cart is empty.'))
  const total = cart.reduce((sum, i) => sum + i.price * i.qty, 0)
  return h('section', {},
    h('h1', {}, 'Your cart'),
    h('table', {}, h('tbody', {}, cart.map(i => h('tr', {},
      h('td', {}, i.name),
      h('td', {}, money(i.price)),
      h('td', {}, h('input', { type: 'number', min: 1, max: i.stock, value: i.qty, 'aria-label': 'Quantity',
        onchange: e => { i.qty = Math.max(1, Math.min(i.stock, +e.target.value || 1)); saveCart(); render() } })),
      h('td', {}, h('button', { class: 'ghost', onclick: () => { cart = cart.filter(x => x !== i); saveCart(); render() } }, 'Remove')))))),
    h('p', {}, h('strong', {}, 'Total: ' + money(total)), ' (the server checks the final price)'),
    h('button', { onclick: checkout }, 'Checkout'))
}

function checkout() {
  if (!user) { flash('Please log in first'); location.hash = 'login'; return }
  location.hash = 'checkout'
}

function checkoutView() {
  if (!user) return needLogin()
  if (!cart.length) return h('section', {}, h('h1', {}, 'Checkout'), h('p', { class: 'muted' }, 'Your cart is empty.'))
  const total = cart.reduce((sum, i) => sum + i.price * i.qty, 0)
  const txn = h('input', { placeholder: 'Telebirr transaction ID', 'aria-label': 'Transaction ID', required: true, minlength: 4, maxlength: 60 })
  const receipt = h('input', { type: 'file', accept: 'image/jpeg,image/png,image/webp', 'aria-label': 'Payment screenshot', required: true })
  const form = h('form', { class: 'form', onsubmit: async e => {
    e.preventDefault()
    const data = new FormData()
    data.append('items', JSON.stringify(cart.map(i => ({ product_id: i.id, quantity: i.qty }))))
    data.append('transaction_id', txn.value)
    data.append('receipt', receipt.files[0])
    try {
      const res = await fetch('/api/orders', { method: 'POST', headers: { 'X-CSRF-Token': csrf }, body: data })
      const out = await res.json()
      if (!res.ok) throw new Error(out.error)
      cart = []
      saveCart()
      flash(`Order #${out.order_id} received. We will confirm your payment soon.`)
      location.hash = 'account'
    } catch (err) {
      flash(err.message)
    }
  } }, txn, h('label', {}, 'Payment screenshot ', receipt), h('button', { type: 'submit' }, 'Confirm Order'))
  return h('section', {},
    h('h1', {}, 'Checkout'),
    h('div', { class: 'card' },
      h('h2', {}, 'Pay with Telebirr'),
      h('p', {}, 'Amount to pay:'),
      h('p', { class: 'price' }, money(total)),
      h('p', {}, 'Send it to this Telebirr number:'),
      h('p', { class: 'price' }, store.payment.telebirrNumber),
      h('p', { class: 'muted' }, `Account name: ${store.payment.accountName}`),
      h('p', { class: 'muted' }, 'Then type the transaction ID from the Telebirr SMS and upload a screenshot of the payment.')),
    form)
}

async function notificationsView() {
  if (!user) return needLogin()
  const { notifications } = await api('/notifications')
  await api('/notifications/read', 'POST')
  unread = 0
  drawNav()
  return h('section', {}, h('h1', {}, 'Notifications'),
    notifications.length
      ? h('ul', {}, notifications.map(n => h('li', { style: n.is_read ? '' : 'font-weight: 600' }, `${n.message} `, h('span', { class: 'muted' }, n.created_at))))
      : h('p', { class: 'muted' }, 'No notifications yet.'))
}

async function adminOrders() {
  const { orders } = await api('/admin/orders')
  if (!orders.length) return h('p', { class: 'muted' }, 'No orders yet.')
  const decide = (o, action) => async () => {
    try {
      await api(`/admin/orders/${o.id}/${action}`, 'POST')
      flash(`Order #${o.id} ${action === 'approve' ? 'approved' : 'rejected'}`)
      render()
    } catch (err) {
      flash(err.message)
    }
  }
  const move = (o, status) => async () => {
    if (status === 'Cancelled' && !confirm(tr(`Cancel order #${o.id}? The stock goes back to the shelf.`))) return
    try {
      await api(`/admin/orders/${o.id}/status`, 'POST', { status })
      flash(`Order #${o.id}: ${status}`)
      render()
    } catch (err) {
      flash(err.message)
    }
  }
  const steps = o => {
    const next = { Processing: ['Shipped', 'Cancelled'], Shipped: ['Delivered', 'Cancelled'] }[o.status]
    if (!next) return o.status
    return [o.status + ': ', ...next.flatMap(s => [
      h('button', { class: s === 'Cancelled' ? 'ghost' : '', onclick: move(o, s) }, s === 'Cancelled' ? 'Cancel' : 'Mark ' + s.toLowerCase()), ' '])]
  }
  const heads = ['Order', 'Customer', 'Items', 'Total', 'Transaction ID', 'Receipt', 'Payment', 'Action']
  return h('div', { style: 'overflow-x: auto' }, h('table', {},
    h('thead', {}, h('tr', {}, heads.map(t => h('th', { scope: 'col', style: 'text-align: left' }, t)))),
    h('tbody', {}, orders.map(o => h('tr', {},
      h('td', {}, '#' + o.id),
      h('td', {}, `${o.name} (${o.phone || o.email})`),
      h('td', {}, o.items),
      h('td', {}, money(o.total_cents)),
      h('td', {}, o.transaction_id),
      h('td', {}, o.has_receipt ? h('a', { href: '/api/receipt/' + o.id, target: '_blank', rel: 'noopener' }, 'View receipt') : '-'),
      h('td', {}, o.payment_status),
      h('td', {}, o.payment_status === 'Pending'
        ? [h('button', { onclick: decide(o, 'approve') }, 'Approve'), ' ', h('button', { class: 'ghost', onclick: decide(o, 'reject') }, 'Reject')]
        : steps(o)))))))
}

const needLogin = () => h('section', {}, h('h1', {}, 'Please log in'), h('a', { href: '#login' }, 'Go to the login page'))

async function orders() {
  if (!user) return needLogin()
  const data = await api('/orders')
  return h('section', {},
    h('h1', {}, 'My orders'),
    data.orders.length
      ? h('table', {}, h('tbody', {}, data.orders.map(o => h('tr', {},
          h('td', {}, '#' + o.id), h('td', {}, o.created_at), h('td', {}, o.status), h('td', {}, o.payment_status), h('td', {}, money(o.total_cents))))))
      : h('p', { class: 'muted' }, 'No orders yet.'))
}

function login() {
  const email = h('input', { type: 'email', placeholder: 'Email', 'aria-label': 'Email', required: true })
  const pass = h('input', { type: 'password', placeholder: 'Password', 'aria-label': 'Password', required: true })
  const remember = h('input', { type: 'checkbox' })
  const form = h('form', { class: 'form', onsubmit: async e => {
    e.preventDefault()
    try {
      await api('/login', 'POST', { email: email.value, password: pass.value, remember: remember.checked })
      await refreshMe()
      flash('Welcome, ' + user.name)
      location.hash = 'shop'
    } catch (err) {
      flash(err.message)
    }
  } }, email, pass, h('label', {}, remember, ' Remember me'), h('button', { type: 'submit' }, 'Log in'),
    h('a', { href: '#forgot' }, 'Forgot password?'), h('a', { href: '#register' }, 'Create an account'))
  return h('section', {}, h('h1', {}, 'Log in'), form)
}

// Shows a one-field or two-field form, then swaps it for the server's message.
function messageForm(title, fields, button, send, extra, resend) {
  const box = h('section', {}, h('h1', {}, title))
  const form = h('form', { class: 'form', onsubmit: async e => {
    e.preventDefault()
    try {
      const res = await send()
      box.replaceChildren(h('h1', {}, title), h('p', {}, res.message), resend ? resendButton(resend) : null, extra || null)
    } catch (err) {
      flash(err.message)
    }
  } }, ...fields, h('button', { type: 'submit' }, button))
  box.append(form)
  return box
}

function register() {
  const name = h('input', { placeholder: 'Full name', 'aria-label': 'Full name', required: true })
  const email = h('input', { type: 'email', placeholder: 'Email', 'aria-label': 'Email', required: true })
  const pass = h('input', { type: 'password', placeholder: 'Password (8+ characters)', 'aria-label': 'Password', required: true, minlength: 8 })
  return messageForm('Create account', [name, email, pass], 'Register',
    () => api('/register', 'POST', { name: name.value, email: email.value, password: pass.value }),
    null, () => api('/resend-verification', 'POST', { email: email.value }))
}

function forgot() {
  const email = h('input', { type: 'email', placeholder: 'Registered email', 'aria-label': 'Email', required: true })
  const send = () => api('/forgot-password', 'POST', { email: email.value })
  return messageForm('Forgot password', [email], 'Send Reset Link', send, null, send)
}

function resetView() {
  const token = new URLSearchParams(location.search).get('token') || ''
  const pass = h('input', { type: 'password', placeholder: 'New password (8+ characters)', 'aria-label': 'New password', required: true, minlength: 8 })
  const again = h('input', { type: 'password', placeholder: 'Confirm new password', 'aria-label': 'Confirm password', required: true, minlength: 8 })
  return messageForm('Choose a new password', [pass, again], 'Update Password', async () => {
    if (pass.value !== again.value) throw new Error('The two passwords do not match')
    return api('/reset-password', 'POST', { token, password: pass.value })
  }, h('a', { href: '/#login' }, 'Go to login'))
}

async function account() {
  if (!user) return needLogin()
  const name = h('input', { value: user.name, 'aria-label': 'Full name', required: true })
  const phone = h('input', { value: user.phone, placeholder: 'Phone', 'aria-label': 'Phone' })
  const address = h('textarea', { placeholder: 'Shipping address', 'aria-label': 'Shipping address' }, user.address)
  const profile = h('form', { class: 'form', onsubmit: async e => {
    e.preventDefault()
    try {
      await api('/profile', 'POST', { name: name.value, phone: phone.value, address: address.value })
      await refreshMe()
      flash('Profile saved')
    } catch (err) {
      flash(err.message)
    }
  } }, h('input', { value: user.email, disabled: true, 'aria-label': 'Email' }), name, phone, address,
    h('button', { type: 'submit' }, 'Save profile'))

  const oldPass = h('input', { type: 'password', placeholder: 'Current password', 'aria-label': 'Current password', required: true })
  const newPass = h('input', { type: 'password', placeholder: 'New password (8+ characters)', 'aria-label': 'New password', required: true, minlength: 8 })
  const password = h('form', { class: 'form', onsubmit: async e => {
    e.preventDefault()
    try {
      await api('/change-password', 'POST', { old_password: oldPass.value, new_password: newPass.value })
      e.target.reset()
      flash('Password changed')
    } catch (err) {
      flash(err.message)
    }
  } }, oldPass, newPass, h('button', { type: 'submit' }, 'Change password'))

  return h('section', {},
    h('h1', {}, 'My account'),
    avatarEditor(),
    h('h2', { class: 'section' }, 'Profile'), profile,
    await orders(),
    h('h2', { class: 'section' }, 'Wishlist'), await wishlistView(),
    h('h2', { class: 'section' }, 'Change password'), password)
}

async function admin() {
  if (!user || user.role !== 'admin') return needLogin()
  const stats = h('div', {})
  try {
    const base = store.analyticsUrl
    const summary = await getJson(base + '/summary')
    const top = await getJson(base + '/top-products')
    stats.append(
      h('div', { class: 'grid' }, [['Orders', summary.orders], ['Revenue', money(summary.revenue_cents)],
        ['Customers', summary.customers], ['Items sold', summary.items_sold], ['Pending', summary.pending_orders],
        ['Cancelled', summary.cancelled_orders], ['Low stock items', summary.low_stock_products]]
        .map(([label, value]) => h('div', { class: 'card' }, h('p', { class: 'muted' }, label), h('h2', {}, String(value))))),
      h('h2', {}, 'Top products'),
      h('ul', {}, top.map(t => h('li', {}, `${t.name}: ${t.units_sold} sold`))),
      h('h2', {}, 'Revenue'), revenueChart(base))
  } catch (err) {
    stats.append(h('p', { class: 'muted' }, 'Analytics service is offline. Start analytics/app.py to see sales numbers.'))
  }

  const [{ products }, { categories }] = await Promise.all([api('/products'), api('/categories')])
  return h('section', {}, h('h1', {}, 'Admin'), avatarEditor(), stats,
    h('h2', { class: 'section' }, 'Orders to verify'), await adminOrders(),
    h('h2', { class: 'section' }, 'Products'),
    products.some(p => p.stock <= 5) ? h('p', { class: 'stock low' }, 'Low stock: ' + products.filter(p => p.stock <= 5).map(p => p.name).join(', ')) : null,
    productManager(products, categories),
    h('h2', { class: 'section' }, 'Customers'), await customerManager(),
    h('h2', { class: 'section' }, 'Reviews'), await reviewManager())
}

async function wishlistView() {
  const { products } = await api('/wishlist')
  if (!products.length) return h('p', { class: 'muted' }, 'Nothing saved yet. Use "Save to wishlist" on a product page.')
  return h('div', { class: 'grid' }, products.map(p => h('article', { class: 'card' },
    h('h2', {}, h('a', { href: '#product/' + p.id }, p.name)),
    h('p', { class: 'price' }, money(priceOf(p))),
    h('button', { class: 'ghost', onclick: async () => { await api('/wishlist/' + p.id, 'POST'); render() } }, 'Remove'))))
}

async function reviewManager() {
  const { reviews } = await api('/admin/reviews')
  if (!reviews.length) return h('p', { class: 'muted' }, 'No reviews yet.')
  const remove = r => async () => {
    if (!confirm(tr('Delete this review?'))) return
    try {
      await api(`/admin/reviews/${r.id}/delete`, 'POST')
      flash('Review deleted')
      render()
    } catch (err) {
      flash(err.message)
    }
  }
  return h('div', { style: 'overflow-x: auto' }, h('table', {}, h('tbody', {}, reviews.map(r => h('tr', {},
    h('td', {}, r.product), h('td', {}, r.name), h('td', {}, `${r.rating}/5`), h('td', {}, r.comment),
    h('td', {}, h('button', { class: 'ghost', onclick: remove(r) }, 'Delete')))))))
}

// A small bar chart drawn with plain SVG, so nothing extra has to be downloaded.
function svg(tag, attrs = {}, ...kids) {
  const el = document.createElementNS('http://www.w3.org/2000/svg', tag)
  for (const [key, value] of Object.entries(attrs)) el.setAttribute(key, value)
  el.append(...kids)
  return el
}

function revenueChart(base) {
  const chart = h('div', { style: 'overflow-x: auto' })
  const draw = async period => {
    try {
      const rows = await getJson(`${base}/revenue?period=${period}`)
      if (!rows.length) { chart.replaceChildren(h('p', { class: 'muted' }, 'No paid sales yet.')); return }
      const max = Math.max(...rows.map(r => r.revenue_cents))
      const bars = rows.flatMap((r, i) => {
        const height = Math.max(2, Math.round((r.revenue_cents / max) * 130))
        const x = i * 64 + 10
        return [
          svg('rect', { x, y: 150 - height, width: 44, height, rx: 4, fill: 'var(--accent)' }),
          svg('text', { x: x + 22, y: 144 - height, 'text-anchor': 'middle', 'font-size': 11, fill: 'var(--fg)' }, String(Math.round(r.revenue_cents / 100))),
          svg('text', { x: x + 22, y: 168, 'text-anchor': 'middle', 'font-size': 10, fill: 'var(--muted)' }, period === 'month' ? r.label : r.label.slice(5))]
      })
      chart.replaceChildren(svg('svg', { width: rows.length * 64 + 20, height: 180, role: 'img', 'aria-label': `Revenue per ${period} in ${store.currency}` }, ...bars))
    } catch (err) {
      chart.replaceChildren(h('p', { class: 'muted' }, 'Could not load the chart. Is analytics/app.py running?'))
    }
  }
  const buttons = [['day', 'Daily'], ['week', 'Weekly'], ['month', 'Monthly']]
    .map(([period, label]) => h('button', { class: 'ghost', onclick: () => draw(period) }, label))
  draw('day')
  return h('div', {}, h('div', {}, ...buttons), chart)
}

async function customerManager() {
  const { customers } = await api('/admin/customers')
  const change = (c, action, body, message) => async () => {
    try {
      await api(`/admin/customers/${c.id}/${action}`, 'POST', body)
      flash(message)
      render()
    } catch (err) {
      flash(err.message)
    }
  }
  const heads = ['Name', 'Email', 'Role', 'Verified', 'Status', 'Orders', 'Actions']
  return h('div', { style: 'overflow-x: auto' }, h('table', {},
    h('thead', {}, h('tr', {}, heads.map(t => h('th', { scope: 'col', style: 'text-align: left' }, t)))),
    h('tbody', {}, customers.map(c => h('tr', {},
      h('td', {}, c.name), h('td', {}, c.email), h('td', {}, c.role), h('td', {}, c.is_verified ? 'Yes' : 'No'),
      h('td', {}, c.banned ? 'Suspended' : 'Active'), h('td', {}, String(c.orders)),
      h('td', {}, Number(c.id) === Number(user.id) ? '(you)' : [
        h('button', { class: 'ghost', onclick: change(c, 'role', { role: c.role === 'admin' ? 'customer' : 'admin' }, 'Role updated') }, c.role === 'admin' ? 'Make customer' : 'Make admin'), ' ',
        h('button', { class: 'ghost', onclick: change(c, 'ban', { banned: !c.banned }, c.banned ? 'Account restored' : 'Account suspended') }, c.banned ? 'Unban' : 'Ban')]))))))
}

let editing = null

function productManager(products, categories) {
  const box = h('div', {})
  const draw = () => {
    const p = editing || { name: '', description: '', price_cents: '', discount_cents: 0, stock: '', brand: '', category_id: '', image_url: '' }
    const field = (label, attrs) => h('input', { 'aria-label': label, placeholder: label, ...attrs })
    const name = field('Product name', { value: p.name, required: true })
    const description = h('textarea', { 'aria-label': 'Description', placeholder: 'Description' }, p.description)
    const price = field('Price', { type: 'number', step: '0.01', min: 0, value: p.price_cents === '' ? '' : p.price_cents / 100, required: true })
    const discount = field('Discount price (optional)', { type: 'number', step: '0.01', min: 0, value: p.discount_cents ? p.discount_cents / 100 : '' })
    const stock = field('Stock', { type: 'number', min: 0, value: p.stock, required: true })
    const brand = field('Brand', { value: p.brand || '' })
    const category = h('select', { 'aria-label': 'Category' }, h('option', { value: '' }, 'No category'),
      ...categories.map(c => h('option', { value: c.id, selected: c.id === p.category_id }, c.name)))
    const link = field('Main image link (optional)', { value: p.image_url || '' })
    const main = h('input', { type: 'file', accept: 'image/jpeg,image/png,image/webp', 'aria-label': 'Main picture' })
    const extras = h('input', { type: 'file', multiple: true, accept: 'image/jpeg,image/png,image/webp', 'aria-label': 'Extra pictures' })
    const form = h('form', { class: 'form', onsubmit: async e => {
      e.preventDefault()
      const data = new FormData()
      const send = { name: name.value, description: description.value, price_cents: Math.round(+price.value * 100),
        discount_cents: Math.round(+discount.value * 100), stock: stock.value, brand: brand.value, category_id: category.value, image_url: link.value }
      Object.entries(send).forEach(([key, value]) => data.append(key, value))
      if (main.files[0]) data.append('main', main.files[0])
      ;[...extras.files].slice(0, 4).forEach(file => data.append('extras[]', file))
      try {
        await upload(editing ? '/admin/products/' + editing.id : '/admin/products', data)
        editing = null
        flash('Product saved')
        render()
      } catch (err) {
        flash(err.message)
      }
    } }, name, description, price, discount, stock, brand, category, link,
      h('label', {}, 'Main picture ', main), h('label', {}, 'Extra pictures (up to 4) ', extras),
      h('button', { type: 'submit' }, editing ? 'Save changes' : 'Add product'),
      editing ? h('button', { type: 'button', class: 'ghost', onclick: () => { editing = null; draw() } }, 'Cancel') : null)

    const remove = item => async () => {
      if (!confirm(tr(`Delete "${item.name}"?`))) return
      try {
        await upload(`/admin/products/${item.id}/delete`, new FormData())
        flash('Product deleted')
        render()
      } catch (err) {
        flash(err.message)
      }
    }
    const heads = ['Product', 'Category', 'Price', 'Discount', 'Stock', 'Actions']
    box.replaceChildren(
      h('div', { style: 'overflow-x: auto' }, h('table', {},
        h('thead', {}, h('tr', {}, heads.map(t => h('th', { scope: 'col', style: 'text-align: left' }, t)))),
        h('tbody', {}, products.map(item => h('tr', {},
          h('td', {}, item.name), h('td', {}, item.category || '-'), h('td', {}, money(item.price_cents)),
          h('td', {}, item.discount_cents ? money(item.discount_cents) : '-'),
          h('td', {}, item.stock <= 5 ? `${item.stock} (low stock)` : String(item.stock)),
          h('td', {}, h('button', { class: 'ghost', onclick: () => { editing = item; draw() } }, 'Edit'), ' ',
            h('button', { class: 'ghost', onclick: remove(item) }, 'Delete'))))))),
      h('h2', { class: 'section' }, editing ? 'Edit product' : 'Add a product'), form)
  }
  draw()
  return box
}

async function render() {
  drawNav()
  const [page, arg] = (location.pathname === '/reset-password.php' ? 'reset' : location.hash.slice(1) || 'shop').split('/')
  const view = views[page] || shop
  try {
    $('#view').replaceChildren(await view(arg))
  } catch (err) {
    $('#view').replaceChildren(h('p', { class: 'muted' }, err.message))
  }
}

$('#auth').onclick = async () => {
  if (!user) { location.hash = 'login'; return }
  await api('/logout', 'POST')
  await refreshMe()
  flash('Logged out')
  location.hash = 'shop'
  render()
}
$('#theme').onclick = () => {
  const dark = document.documentElement.classList.toggle('dark')
  localStorage.setItem('theme', dark ? 'dark' : 'light')
}
$('#search').oninput = e => {
  query = e.target.value.trim().toLowerCase()
  if ((location.hash.slice(1) || 'shop') !== 'shop') location.hash = 'shop'
  else drawGrid()
}

// ----- Profile picture -----
function avatar(size) {
  const style = `width: ${size}px; height: ${size}px; font-size: ${Math.round(size / 2.4)}px`
  return user.photo
    ? h('img', { class: 'avatar', src: user.photo, alt: '', style })
    : h('span', { class: 'avatar', style }, [...(user.name || '?')][0].toUpperCase())
}

// A round picture with one button underneath. Choosing a file uploads it straight away.
function avatarEditor() {
  const input = h('input', { type: 'file', accept: 'image/jpeg,image/png,image/webp', hidden: true, 'aria-label': 'Profile photo',
    onchange: async () => {
      if (!input.files[0]) return
      const data = new FormData()
      data.append('photo', input.files[0])
      try {
        await upload('/profile/photo', data)
        await refreshMe()
        flash('Photo updated')
        render()
      } catch (err) {
        flash(err.message)
      }
    } })
  return h('div', { class: 'avatar-editor' }, avatar(120), input,
    h('button', { type: 'button', class: 'ghost', onclick: () => input.click() }, user.photo ? 'Change Picture' : 'Upload Photo'))
}

// ----- "Resend" button that unlocks after a 2 minute countdown -----
function resendButton(send) {
  const button = h('button', { type: 'button', class: 'ghost', onclick: async () => {
    try {
      const res = await send()
      flash(res.message)
      start()
    } catch (err) {
      flash(err.message)
    }
  } })
  const start = () => {
    let left = 120
    button.disabled = true
    button.textContent = 'Resend in 2:00'
    const timer = setInterval(() => {
      left -= 1
      if (!button.isConnected || left <= 0) {
        clearInterval(timer)
        button.disabled = false
        button.textContent = 'Resend'
        return
      }
      button.textContent = `Resend in ${Math.floor(left / 60)}:${String(left % 60).padStart(2, '0')}`
    }, 1000)
  }
  start()
  return button
}

// ----- Language: English / Amharic -----
// Pages are written in English. In Amharic mode every known text is swapped using data/lang.json,
// and the original English is remembered so switching back is exact.
let dict = { exact: {}, patterns: [] }
let lang = localStorage.getItem('lang') === 'am' ? 'am' : 'en'
const TEXT_ATTRS = ['placeholder', 'aria-label', 'title', 'alt']

function tr(text) {
  if (lang !== 'am' || !text) return text
  const core = text.trim()
  if (!core) return text
  const lead = text.slice(0, text.indexOf(core))
  const tail = text.slice(lead.length + core.length)
  let out = dict.exact[core]
  if (out === undefined) {
    for (const [pattern, template] of dict.patterns) {
      const match = core.match(new RegExp(pattern))
      if (match) {
        out = template.replace(/([$@])(\d)/g, (_, kind, n) => (kind === '@' ? tr(match[n]) : match[n]))
        break
      }
    }
  }
  return lead + (out ?? core).replace(/(\d) ETB\b/g, '$1 ብር') + tail
}

function translateTree(node) {
  if (node.nodeType === 3) {
    if (node._en === undefined) node._en = node.data
    const next = lang === 'am' ? tr(node._en) : node._en
    if (node.data !== next) node.data = next
    return
  }
  if (node.nodeType !== 1 || ['SCRIPT', 'STYLE', 'TEXTAREA'].includes(node.tagName)) return
  for (const name of TEXT_ATTRS) {
    if (!node.hasAttribute(name)) continue
    node._attrs = node._attrs || {}
    if (!(name in node._attrs)) node._attrs[name] = node.getAttribute(name)
    const next = lang === 'am' ? tr(node._attrs[name]) : node._attrs[name]
    if (node.getAttribute(name) !== next) node.setAttribute(name, next)
  }
  node.childNodes.forEach(translateTree)
}

function setLang(next) {
  lang = next
  localStorage.setItem('lang', next)
  document.cookie = `lang=${next}; path=/; max-age=31536000; samesite=lax`
  document.documentElement.lang = next
  $('#lang').textContent = next === 'am' ? 'EN' : 'አማ'
  document.title = `${tr(store.storeName)} · ${tr(store.owner)}`
  translateTree(document.body)
}

$('#lang').onclick = () => setLang(lang === 'am' ? 'en' : 'am')

async function start() {
  store = await (await fetch('/data/store.json')).json()
  document.title = `${store.storeName} · ${store.owner}`
  $('.logo-mark').style.backgroundImage = `url(${store.images.logo})`
  $('#storeName').textContent = store.storeName
  drawFooter()
  try {
    dict = await (await fetch('/data/lang.json')).json()
  } catch (err) {
    // no dictionary: the shop simply stays in English
  }
  new MutationObserver(list => list.forEach(m => m.addedNodes.forEach(translateTree))).observe(document.body, { childList: true, subtree: true })
  setLang(lang)
  await refreshMe()
  addEventListener('hashchange', render)
  render()
}
start()