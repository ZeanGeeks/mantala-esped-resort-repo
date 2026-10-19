import { useEffect, useMemo, useState } from 'react'
import { BrowserRouter, Link, NavLink, Navigate, Route, Routes, useLocation, useNavigate } from 'react-router-dom'
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { Calendar, dateFnsLocalizer } from 'react-big-calendar'
import { format, parse, startOfWeek, getDay } from 'date-fns'
import enUS from 'date-fns/locale/en-US'
import resortBackground from './assets/images/resort-background.jpg'
import { getCsrf, request } from './lib/api'
import 'react-big-calendar/lib/css/react-big-calendar.css'
import './App.css'

const locales = { 'en-US': enUS }
const localizer = dateFnsLocalizer({
  format,
  parse,
  startOfWeek,
  getDay,
  locales,
})

const guestLinks = [
  { to: '/', label: 'Home' },
  { to: '/about', label: 'About' },
  { to: '/services', label: 'Services' },
  { to: '/recommendation', label: 'AI Recommendation' },
  { to: '/booking', label: 'Booking' },
  { to: '/my-bookings', label: 'My Bookings' },
  { to: '/contact', label: 'Contact' },
]

const adminLinks = [
  { to: '/admin', label: 'Dashboard' },
  { to: '/admin/bookings', label: 'Booking Management' },
  { to: '/admin/services', label: 'Services Management' },
  { to: '/admin/guests', label: 'Guest Management' },
  { to: '/admin/feedback', label: 'Feedback' },
  { to: '/admin/reports', label: 'Reports' },
  { to: '/admin/activity', label: 'Activity Logs' },
  { to: '/admin/settings', label: 'System Settings' },
]

function useApi() {
  const [csrfToken, setCsrfToken] = useState('')

  useEffect(() => {
    getCsrf().then((res) => setCsrfToken(res.csrf_token || '')).catch(() => {})
  }, [])

  const api = async (path, options = {}) => {
    if (!csrfToken && options.method && options.method !== 'GET') {
      const tokenRes = await getCsrf()
      setCsrfToken(tokenRes.csrf_token || '')
      return request(path, { ...options, csrfToken: tokenRes.csrf_token })
    }
    return request(path, { ...options, csrfToken })
  }

  return { api, csrfToken, refreshCsrf: async () => {
    const res = await getCsrf()
    setCsrfToken(res.csrf_token || '')
    return res.csrf_token
  } }
}

function AppShell({ children, user, onLogout }) {
  const location = useLocation()
  const isAdminRoute = location.pathname.startsWith('/admin')

  return (
    <div className="site-shell" style={{ '--resort-background': `url(${resortBackground})` }}>
      <div className="utility-bar">
        <span>ESPED Resort • Classic comfort</span>
        <span>{user ? `Signed in as ${user.username} (${user.role})` : 'Guest access'}</span>
      </div>
      <header className="site-header">
        <Link className="brand" to="/">
          <span className="brand-mark">E</span>
          <span>ESPED Resort</span>
        </Link>
        <nav aria-label="Main navigation">
          {(isAdminRoute ? adminLinks : guestLinks).map((item) => (
            <NavLink key={item.to} to={item.to} className={({ isActive }) => (isActive ? 'active-link' : '')}>
              {item.label}
            </NavLink>
          ))}
          {user ? (
            <button className="nav-button" onClick={onLogout}>Logout</button>
          ) : (
            <Link className="nav-button" to="/login">Login</Link>
          )}
        </nav>
      </header>
      {children}
    </div>
  )
}

function Banner({ title, breadcrumb, hero = false, children }) {
  return (
    <section className={hero ? 'welcome-section' : 'welcome-section page-banner'}>
      <div className="hero-copy">
        <p className="eyebrow">{breadcrumb}</p>
        <h1>{title}</h1>
        {children}
      </div>
    </section>
  )
}

function HomePage({ homeData }) {
  return (
    <main>
      <Banner title="Welcome to ESPED Resort" breadcrumb="Home" hero>
        <p className="tagline">A peaceful place to rest, relax, and enjoy your stay.</p>
        <div className="hero-actions">
          <Link className="button" to="/services">Browse Services</Link>
          <Link className="text-link" to="/recommendation">Ask the AI Assistant</Link>
        </div>
      </Banner>

      <section className="content-section intro-section">
        <div>
          <p className="section-kicker">About preview</p>
          <h2>About ESPED Resort</h2>
        </div>
        <div className="intro-detail">
          <p>{homeData.about_preview || 'Discover our spaces, services, and warm hospitality.'}</p>
          <Link className="text-link dark-link" to="/about">Read more</Link>
        </div>
      </section>

      <section className="content-section stay-section">
        <div className="section-heading">
          <div>
            <p className="section-kicker">Featured services</p>
            <h2>Recommended stays</h2>
          </div>
          <p>Handpicked options for your next visit.</p>
        </div>
        <div className="feature-grid">
          {(homeData.featured_services || []).slice(0, 6).map((service) => (
            <article className="feature-card" key={service.id}>
              <span className="feature-number">{service.service_type}</span>
              <h3>{service.name}</h3>
              <p>{service.description}</p>
              <p className="meta">₱{Number(service.price).toFixed(2)} • Capacity {service.capacity}</p>
            </article>
          ))}
        </div>
      </section>

      <section className="content-section mini-ai-box">
        <h3>Need help deciding?</h3>
        <p>Try the AI recommendation form with your budget, guest count, and preferences.</p>
        <Link className="button" to="/recommendation">Go to Recommendation</Link>
      </section>

      <section className="contact-section">
        <h2>Ready to book your stay?</h2>
        <Link className="button" to="/booking">Book now</Link>
      </section>
    </main>
  )
}

function AboutOrContactPage({ title, breadcrumb, info }) {
  return (
    <main>
      <Banner title={title} breadcrumb={breadcrumb} />
      <section className="content-section">
        <p>{info?.description}</p>
        <div className="two-column">
          <div>
            <p><strong>Address:</strong> {info?.address}</p>
            <p><strong>Phone:</strong> {info?.phone}</p>
            <p><strong>Email:</strong> {info?.email}</p>
          </div>
          <iframe
            title="Google map"
            src={info?.google_maps_url || 'https://www.google.com/maps?q=14.5995,120.9842&output=embed'}
            width="100%"
            height="300"
            style={{ border: 0 }}
            loading="lazy"
          />
        </div>
      </section>
    </main>
  )
}

function ServicesPage({ services, onSearch }) {
  const [search, setSearch] = useState('')
  const [serviceType, setServiceType] = useState('')
  const [sort, setSort] = useState('asc')

  useEffect(() => {
    const id = setTimeout(() => onSearch({ search, service_type: serviceType, sort }), 250)
    return () => clearTimeout(id)
  }, [search, serviceType, sort, onSearch])

  return (
    <main>
      <Banner title="Services" breadcrumb="Home / Services" />
      <section className="content-section">
        <div className="toolbar">
          <input placeholder="Search services..." value={search} onChange={(e) => setSearch(e.target.value)} />
          <select value={serviceType} onChange={(e) => setServiceType(e.target.value)}>
            <option value="">All types</option>
            <option value="cottage">Cottage</option>
            <option value="room">Room</option>
            <option value="amenity">Amenity</option>
            <option value="package">Package</option>
          </select>
          <select value={sort} onChange={(e) => setSort(e.target.value)}>
            <option value="asc">Price: Low to High</option>
            <option value="desc">Price: High to Low</option>
          </select>
        </div>
        <div className="feature-grid">
          {services.map((service) => (
            <article className="feature-card" key={service.id}>
              <span className="feature-number">{service.service_type}</span>
              <h3>{service.name}</h3>
              <p>{service.description}</p>
              <p className="meta">₱{Number(service.price).toFixed(2)} • Capacity {service.capacity}</p>
              <Link className="text-link dark-link" to="/booking">Add to booking</Link>
            </article>
          ))}
        </div>
      </section>
    </main>
  )
}

function RecommendationPage({ api, user }) {
  const [form, setForm] = useState({ maximum_budget: '', number_of_guests: '', preferences: '' })
  const [result, setResult] = useState(null)
  const [error, setError] = useState('')

  const submit = async (e) => {
    e.preventDefault()
    setError('')
    try {
      const res = await api('/recommendation', { method: 'POST', body: form })
      setResult(res)
    } catch (err) {
      setError(err.message)
    }
  }

  return (
    <main>
      <Banner title="AI Recommendation" breadcrumb="Home / Recommendation" />
      <section className="content-section">
        <form className="form-grid" onSubmit={submit}>
          <input type="number" placeholder="Maximum budget" value={form.maximum_budget} onChange={(e) => setForm({ ...form, maximum_budget: e.target.value })} required />
          <input type="number" placeholder="Number of guests" value={form.number_of_guests} onChange={(e) => setForm({ ...form, number_of_guests: e.target.value })} required />
          <textarea placeholder="Preferences" value={form.preferences} onChange={(e) => setForm({ ...form, preferences: e.target.value })} />
          <button className="button" type="submit">Get recommendation</button>
        </form>
        {error && <p className="error-text">{error}</p>}
        {result && (
          <div className="feature-grid">
            {result.items.map((item) => (
              <article className="feature-card" key={`${item.service.id}-${item.rank_order}`}>
                <span className="feature-number">Rank #{item.rank_order}</span>
                <h3>{item.service.name}</h3>
                <p>{item.match_reason}</p>
                <p className="meta">₱{Number(item.service.price).toFixed(2)} • Capacity {item.service.capacity}</p>
                <Link className="button" to={user ? '/booking' : '/login'}>Book this</Link>
              </article>
            ))}
          </div>
        )}
      </section>
    </main>
  )
}

function BookingPage({ api, user, services, onBookingCreated }) {
  const navigate = useNavigate()
  const [form, setForm] = useState({
    service_id: '',
    check_in_date: '',
    check_out_date: '',
    guests: '',
    special_requests: '',
  })
  const [error, setError] = useState('')
  const [ok, setOk] = useState('')

  useEffect(() => {
    if (!user) {
      navigate('/login')
    }
  }, [user, navigate])

  const submit = async (e) => {
    e.preventDefault()
    setError('')
    setOk('')
    try {
      const res = await api('/bookings', { method: 'POST', body: form })
      setOk(`Booking ${res.booking.reference_no} submitted.`)
      onBookingCreated()
    } catch (err) {
      setError(err.message)
    }
  }

  return (
    <main>
      <Banner title="Booking" breadcrumb="Home / Booking" />
      <section className="content-section">
        <form className="form-grid" onSubmit={submit}>
          <select value={form.service_id} onChange={(e) => setForm({ ...form, service_id: e.target.value })} required>
            <option value="">Select service</option>
            {services.map((service) => <option key={service.id} value={service.id}>{service.name} ({service.service_type})</option>)}
          </select>
          <input type="date" value={form.check_in_date} onChange={(e) => setForm({ ...form, check_in_date: e.target.value })} required />
          <input type="date" value={form.check_out_date} onChange={(e) => setForm({ ...form, check_out_date: e.target.value })} required />
          <input type="number" min="1" placeholder="Guests" value={form.guests} onChange={(e) => setForm({ ...form, guests: e.target.value })} required />
          <textarea placeholder="Special requests" value={form.special_requests} onChange={(e) => setForm({ ...form, special_requests: e.target.value })} />
          <button className="button" type="submit">Submit booking</button>
        </form>
        {error && <p className="error-text">{error}</p>}
        {ok && <p className="ok-text">{ok}</p>}
      </section>
    </main>
  )
}

function MyBookingsPage({ api, rows, refresh }) {
  const [feedbackForm, setFeedbackForm] = useState({})

  const cancelBooking = async (id) => {
    await api(`/bookings/${id}/cancel`, { method: 'DELETE', body: {} })
    refresh()
  }

  const submitFeedback = async (bookingId) => {
    const data = feedbackForm[bookingId]
    if (!data?.rating || !data?.comment) return
    await api(`/bookings/${bookingId}/feedback`, { method: 'POST', body: data })
    refresh()
  }

  return (
    <main>
      <Banner title="My Bookings" breadcrumb="Home / My Bookings" />
      <section className="content-section">
        <table className="table">
          <thead>
            <tr>
              <th>Reference</th>
              <th>Service</th>
              <th>Dates</th>
              <th>Status</th>
              <th>Total</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => (
              <tr key={row.id}>
                <td>{row.reference_no}</td>
                <td>{row.service_name}</td>
                <td>{row.check_in_date} → {row.check_out_date}</td>
                <td><span className={`status status-${row.status.toLowerCase()}`}>{row.status}</span></td>
                <td>₱{Number(row.total_amount).toFixed(2)}</td>
                <td>
                  {row.status === 'Pending' && <button onClick={() => cancelBooking(row.id)}>Cancel</button>}
                  {row.status === 'Completed' && (
                    <div className="inline-feedback">
                      <input type="number" min="1" max="5" placeholder="Rating" onChange={(e) => setFeedbackForm((prev) => ({ ...prev, [row.id]: { ...(prev[row.id] || {}), rating: e.target.value } }))} />
                      <input placeholder="Comment" onChange={(e) => setFeedbackForm((prev) => ({ ...prev, [row.id]: { ...(prev[row.id] || {}), comment: e.target.value } }))} />
                      <button onClick={() => submitFeedback(row.id)}>Submit</button>
                    </div>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </section>
    </main>
  )
}

function AuthPage({ mode, api, onAuth }) {
  const navigate = useNavigate()
  const [form, setForm] = useState({ username: '', email: '', password: '', login_as: 'guest' })
  const [error, setError] = useState('')
  const isLogin = mode === 'login'

  const submit = async (e) => {
    e.preventDefault()
    setError('')
    try {
      if (isLogin) {
        const res = await api('/auth/login', { method: 'POST', body: { email: form.email, password: form.password, login_as: form.login_as } })
        onAuth(res.user)
        navigate(res.user.role === 'admin' ? '/admin' : '/')
      } else {
        await api('/auth/register', { method: 'POST', body: { username: form.username, email: form.email, password: form.password } })
        navigate('/login')
      }
    } catch (err) {
      setError(err.message)
    }
  }

  return (
    <main>
      <Banner title={isLogin ? 'Login' : 'Register'} breadcrumb={isLogin ? 'Home / Login' : 'Home / Register'} />
      <section className="content-section">
        <form className="form-grid narrow" onSubmit={submit}>
          {!isLogin && <input placeholder="Username" value={form.username} onChange={(e) => setForm({ ...form, username: e.target.value })} required />}
          <input placeholder="Email" type="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} required />
          <input placeholder="Password" type="password" value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} required />
          {isLogin && (
            <select value={form.login_as} onChange={(e) => setForm({ ...form, login_as: e.target.value })}>
              <option value="guest">Login as Guest</option>
              <option value="admin">Login as Admin</option>
            </select>
          )}
          <button className="button" type="submit">{isLogin ? 'Login' : 'Register'}</button>
        </form>
        {error && <p className="error-text">{error}</p>}
      </section>
    </main>
  )
}

function AdminDashboard({ dashboard }) {
  return (
    <main className="admin-main">
      <Banner title="Admin Dashboard" breadcrumb="Admin / Dashboard" />
      <section className="content-section">
        <div className="cards">
          <article className="feature-card"><h3>Pending</h3><p>{dashboard.cards?.pending ?? 0}</p></article>
          <article className="feature-card"><h3>Confirmed</h3><p>{dashboard.cards?.confirmed ?? 0}</p></article>
          <article className="feature-card"><h3>Guests</h3><p>{dashboard.cards?.guests ?? 0}</p></article>
          <article className="feature-card"><h3>Revenue</h3><p>₱{Number(dashboard.cards?.revenue ?? 0).toFixed(2)}</p></article>
        </div>
        <div className="chart-shell">
          <ResponsiveContainer width="100%" height={300}>
            <BarChart data={dashboard.chart || []}>
              <CartesianGrid strokeDasharray="3 3" />
              <XAxis dataKey="report_date" />
              <YAxis />
              <Tooltip />
              <Bar dataKey="bookings" fill="#C7A34B" />
            </BarChart>
          </ResponsiveContainer>
        </div>
      </section>
    </main>
  )
}

function AdminBookings({ api, rows, refresh, events }) {
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')

  useEffect(() => {
    const id = setTimeout(() => refresh({ search, status }), 250)
    return () => clearTimeout(id)
  }, [search, status, refresh])

  const act = async (id, action) => {
    await api(`/admin/bookings/${id}/${action}`, { method: 'PUT', body: {} })
    refresh({ search, status })
  }

  const calendarEvents = useMemo(() => (events || []).map((event) => ({
    id: event.id,
    title: `${event.reference_no} • ${event.service_name}`,
    start: new Date(event.check_in_date),
    end: new Date(event.check_out_date),
  })), [events])

  return (
    <main className="admin-main">
      <Banner title="Booking Management" breadcrumb="Admin / Bookings" />
      <section className="content-section">
        <div className="toolbar">
          <input placeholder="Search booking..." value={search} onChange={(e) => setSearch(e.target.value)} />
          <select value={status} onChange={(e) => setStatus(e.target.value)}>
            <option value="">All status</option>
            <option value="Pending">Pending</option>
            <option value="Confirmed">Confirmed</option>
            <option value="Cancelled">Cancelled</option>
            <option value="Completed">Completed</option>
          </select>
        </div>
        <table className="table">
          <thead>
            <tr><th>Reference</th><th>Guest</th><th>Service</th><th>Status</th><th>Action</th></tr>
          </thead>
          <tbody>
            {rows.map((row) => (
              <tr key={row.id}>
                <td>{row.reference_no}</td>
                <td>{row.username}</td>
                <td>{row.service_name}</td>
                <td>{row.status}</td>
                <td className="btn-stack">
                  <button onClick={() => act(row.id, 'confirm')}>Confirm</button>
                  <button onClick={() => act(row.id, 'reject')}>Reject</button>
                  <button onClick={() => act(row.id, 'complete')}>Complete</button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        <div className="calendar-box">
          <Calendar localizer={localizer} events={calendarEvents} startAccessor="start" endAccessor="end" style={{ height: 500 }} />
        </div>
      </section>
    </main>
  )
}

function GenericAdminList({ title, breadcrumb, rows, columns }) {
  return (
    <main className="admin-main">
      <Banner title={title} breadcrumb={breadcrumb} />
      <section className="content-section">
        <table className="table">
          <thead><tr>{columns.map((col) => <th key={col}>{col}</th>)}</tr></thead>
          <tbody>
            {rows.map((row, idx) => (
              <tr key={idx}>
                {columns.map((col) => <td key={col}>{String(row[col] ?? '')}</td>)}
              </tr>
            ))}
          </tbody>
        </table>
      </section>
    </main>
  )
}

function App() {
  const { api } = useApi()
  const [bootError, setBootError] = useState('')
  const [user, setUser] = useState(null)
  const [homeData, setHomeData] = useState({ featured_services: [] })
  const [resortInfo, setResortInfo] = useState(null)
  const [services, setServices] = useState([])
  const [myBookings, setMyBookings] = useState([])
  const [dashboard, setDashboard] = useState({})
  const [adminBookings, setAdminBookings] = useState([])
  const [adminCalendar, setAdminCalendar] = useState([])
  const [adminFeedback, setAdminFeedback] = useState([])
  const [adminGuests, setAdminGuests] = useState([])
  const [adminReports, setAdminReports] = useState([])
  const [adminLogs, setAdminLogs] = useState([])
  const [adminSettings, setAdminSettings] = useState({})

  const loadPublic = async () => {
    const [homeRes, infoRes, servicesRes] = await Promise.all([
      api('/home'),
      api('/resort-info'),
      api('/services'),
    ])
    setHomeData(homeRes)
    setResortInfo(infoRes.resort_info)
    setServices(servicesRes.services || [])
  }

  const loadMe = async () => {
    const me = await api('/auth/me')
    setUser(me.user || null)
  }

  const loadMyBookings = async () => {
    if (!user || user.role !== 'guest') return
    const res = await api('/bookings/my')
    setMyBookings(res.bookings || [])
  }

  const loadAdmin = async () => {
    if (!user || user.role !== 'admin') return
    const [d, b, c, f, g, r, l, s] = await Promise.all([
      api('/admin/dashboard'),
      api('/admin/bookings'),
      api('/admin/bookings/calendar'),
      api('/admin/feedback'),
      api('/admin/guests'),
      api('/admin/reports'),
      api('/admin/activity-logs'),
      api('/admin/settings'),
    ])
    setDashboard(d)
    setAdminBookings(b.bookings || [])
    setAdminCalendar(c.events || [])
    setAdminFeedback(f.feedback || [])
    setAdminGuests(g.guests || [])
    setAdminReports(r.reports || [])
    setAdminLogs(l.activity_logs || [])
    setAdminSettings(s)
  }

  useEffect(() => {
    Promise.all([loadPublic(), loadMe()])
      .catch((err) => setBootError(err.message))
  }, [])

  useEffect(() => {
    if (user?.role === 'guest') loadMyBookings().catch(() => {})
    if (user?.role === 'admin') loadAdmin().catch(() => {})
  }, [user])

  const logout = async () => {
    await api('/auth/logout', { method: 'POST', body: {} })
    setUser(null)
  }

  const refreshAdminBookings = async (query = {}) => {
    const [rowsRes, calRes] = await Promise.all([
      api('/admin/bookings', { query }),
      api('/admin/bookings/calendar'),
    ])
    setAdminBookings(rowsRes.bookings || [])
    setAdminCalendar(calRes.events || [])
    const d = await api('/admin/dashboard')
    setDashboard(d)
  }

  const exportBackup = () => {
    const payload = JSON.stringify(adminSettings, null, 2)
    const blob = new Blob([payload], { type: 'application/json' })
    const a = document.createElement('a')
    a.href = URL.createObjectURL(blob)
    a.download = `esped-settings-${Date.now()}.json`
    a.click()
  }

  if (bootError) {
    return <div className="content-section"><h2>Error</h2><p>{bootError}</p></div>
  }

  return (
    <BrowserRouter>
      <AppShell user={user} onLogout={logout}>
        <Routes>
          <Route path="/" element={<HomePage homeData={homeData} />} />
          <Route path="/about" element={<AboutOrContactPage title="About" breadcrumb="Home / About" info={resortInfo} />} />
          <Route path="/contact" element={<AboutOrContactPage title="Contact" breadcrumb="Home / Contact" info={resortInfo} />} />
          <Route path="/services" element={<ServicesPage services={services} onSearch={async (query) => {
            const res = await api('/services', { query })
            setServices(res.services || [])
          }} />} />
          <Route path="/recommendation" element={<RecommendationPage api={api} user={user} />} />
          <Route path="/booking" element={<BookingPage api={api} user={user} services={services} onBookingCreated={loadMyBookings} />} />
          <Route path="/my-bookings" element={user?.role === 'guest' ? <MyBookingsPage api={api} rows={myBookings} refresh={loadMyBookings} /> : <Navigate to="/login" />} />
          <Route path="/login" element={<AuthPage mode="login" api={api} onAuth={setUser} />} />
          <Route path="/register" element={<AuthPage mode="register" api={api} onAuth={setUser} />} />

          <Route path="/admin" element={user?.role === 'admin' ? <AdminDashboard dashboard={dashboard} /> : <Navigate to="/login" />} />
          <Route path="/admin/bookings" element={user?.role === 'admin' ? <AdminBookings api={api} rows={adminBookings} refresh={refreshAdminBookings} events={adminCalendar} /> : <Navigate to="/login" />} />
          <Route path="/admin/services" element={user?.role === 'admin' ? <GenericAdminList title="Services Management" breadcrumb="Admin / Services" rows={services} columns={['id', 'name', 'service_type', 'price', 'capacity', 'is_active']} /> : <Navigate to="/login" />} />
          <Route path="/admin/guests" element={user?.role === 'admin' ? <GenericAdminList title="Guest Management" breadcrumb="Admin / Guests" rows={adminGuests} columns={['id', 'username', 'email', 'is_active']} /> : <Navigate to="/login" />} />
          <Route path="/admin/feedback" element={user?.role === 'admin' ? <GenericAdminList title="Feedback" breadcrumb="Admin / Feedback" rows={adminFeedback} columns={['id', 'username', 'service_name', 'rating', 'comment', 'created_at']} /> : <Navigate to="/login" />} />
          <Route path="/admin/reports" element={user?.role === 'admin' ? <GenericAdminList title="Reports" breadcrumb="Admin / Reports" rows={adminReports} columns={['report_date', 'booking_count', 'revenue']} /> : <Navigate to="/login" />} />
          <Route path="/admin/activity" element={user?.role === 'admin' ? <GenericAdminList title="Activity Logs" breadcrumb="Admin / Activity Logs" rows={adminLogs} columns={['id', 'admin_username', 'action', 'entity_type', 'entity_id', 'created_at']} /> : <Navigate to="/login" />} />
          <Route path="/admin/settings" element={user?.role === 'admin' ? (
            <main className="admin-main">
              <Banner title="System Settings" breadcrumb="Admin / Settings" />
              <section className="content-section">
                <button className="button" onClick={async () => {
                  const backup = await api('/admin/backup/export')
                  const blob = new Blob([JSON.stringify(backup, null, 2)], { type: 'application/json' })
                  const a = document.createElement('a')
                  a.href = URL.createObjectURL(blob)
                  a.download = `esped-backup-${Date.now()}.json`
                  a.click()
                }}>Database Backup Export</button>
                <button className="button ghost" onClick={exportBackup}>Export loaded settings</button>
              </section>
            </main>
          ) : <Navigate to="/login" />} />
        </Routes>
      </AppShell>
    </BrowserRouter>
  )
}

export default App
