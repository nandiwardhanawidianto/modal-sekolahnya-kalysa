import {NavLink,useNavigate} from 'react-router-dom'
import {api,resetCsrf} from '../lib/api'
export default function Layout({user,stores,storeId,setStoreId,children}){
 const nav=useNavigate();
 const logout=async()=>{await api('/api/logout',{method:'POST'});resetCsrf();nav('/login')}
 return <div className="shell"><aside className="sidebar"><div className="brand"><div className="brand-mark">K</div><div><strong>Modal Sekolahnya</strong><span>Kalysa</span></div></div><nav>
  <NavLink to="/">Semua Toko</NavLink><NavLink to="/report">Laporan Toko</NavLink><NavLink to="/products">Produk & HPP</NavLink><NavLink to="/imports">Import Data</NavLink><NavLink to="/ads">Biaya Iklan</NavLink><NavLink to="/cashflow">Arus Settlement</NavLink><NavLink to="/closings">Closing Bulanan</NavLink><NavLink to="/stores">Kelola Toko</NavLink><NavLink to="/account">Akun</NavLink>
 </nav><div className="sidebar-foot"><small>Login sebagai</small><strong>{user?.name}</strong><button className="link-btn" onClick={logout}>Keluar</button></div></aside><main><header className="topbar"><div><h1>Modal Sekolahnya Kalysa</h1><p>Profit Shopee multi-toko yang bisa dicek ulang.</p></div><label className="store-select">Toko<select value={storeId||''} onChange={e=>setStoreId(e.target.value)}><option value="">Pilih toko</option>{stores.map(s=><option key={s.id} value={s.id}>{s.name}</option>)}</select></label></header><section className="content">{children}</section></main></div>
}
