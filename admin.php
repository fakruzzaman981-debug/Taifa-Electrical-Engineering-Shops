<?php
session_start([
  'cookie_httponly'=>true,
  'cookie_samesite'=>'Strict',
  'cookie_secure'=>(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
]);
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
?>
<!DOCTYPE html>
<html lang="bn">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Taifa Admin</title>
  <style>
    *{box-sizing:border-box}body{margin:0;font-family:Arial,"Noto Sans Bengali",sans-serif;background:#f1f5f9;color:#172033}.top{height:72px;background:#0f172a;color:#fff;display:flex;align-items:center;justify-content:space-between;padding:0 24px}.top a{color:#fff;text-decoration:none}.wrap{max-width:1000px;margin:24px auto;padding:0 16px}.box{background:#fff;border-radius:12px;padding:20px;margin-bottom:16px;box-shadow:0 6px 20px #0001}.grid{display:grid;grid-template-columns:1fr 1.4fr;gap:16px}.field{display:grid;gap:6px;margin:10px 0}.field input{padding:11px;border:1px solid #d6dbe6;border-radius:8px}button{border:0;border-radius:8px;padding:11px 14px;font-weight:bold;cursor:pointer;background:#2563eb;color:#fff}.danger{background:#dc2626}.secondary{background:#e8efff;color:#1d4ed8}.login{max-width:380px;margin:70px auto}.hidden{display:none}.item{display:grid;grid-template-columns:60px 1fr auto;gap:10px;align-items:center;border-bottom:1px solid #eee;padding:10px 0}.item img{width:60px;height:60px;object-fit:cover;border-radius:7px}.item strong,.item span{display:block}.actions{display:flex;gap:6px}.message{min-height:20px;color:#b91c1c}@media(max-width:700px){.grid{grid-template-columns:1fr}.item{grid-template-columns:60px 1fr}.actions{grid-column:1/-1}}
  </style>
</head>
<body>
  <header class="top"><h2>Taifa Admin Panel</h2><a href="index.html">ওয়েবসাইট দেখুন</a></header>
  <main class="wrap">
    <div class="box login" id="loginBox">
      <h2>Admin Login</h2>
      <div class="field"><input type="password" id="password" placeholder="পাসওয়ার্ড"></div>
      <button onclick="login()">লগইন করুন</button>
      <p class="message" id="loginMessage"></p>
    </div>
    <div id="adminBox" class="hidden">
      <div class="grid">
        <div class="box">
          <h3 id="formTitle">নতুন পণ্য</h3>
          <input type="hidden" id="editId">
          <div class="field"><label>পণ্যের নাম</label><input id="name"></div>
          <div class="field"><label>দাম</label><input id="price" type="number" min="0"></div>
          <div class="field"><label>ছবি</label><input id="image" type="file" accept="image/jpeg,image/png,image/webp"></div>
          <button class="secondary" onclick="saveProduct()">সংরক্ষণ করুন</button>
          <button class="danger" onclick="resetForm()">বাতিল</button>
        </div>
        <div class="box"><h3>পণ্যের তালিকা</h3><div id="list"></div></div>
      </div>
      <div class="box">
        <h3>সেটিংস</h3>
        <div class="grid">
          <div class="field"><label>WhatsApp নম্বর</label><input id="whatsapp" value="8801577013053"></div>
          <div class="field"><label>নতুন পাসওয়ার্ড</label><input id="newPassword" type="password"></div>
        </div>
        <button class="secondary" onclick="saveSettings()">সেটিংস সংরক্ষণ</button>
        <button class="danger" onclick="logout()">Logout</button>
        <p class="message" id="settingsMessage"></p>
      </div>
    </div>
  </main>
  <script>
    let csrf=<?php echo json_encode($_SESSION['csrf']); ?>;
    async function api(action,body={},file=false){
      const options={method:'POST',headers:file?{}:{'Content-Type':'application/json'},body:file?body:JSON.stringify({action,csrf,...body})};
      const response=await fetch('api.php',options);
      return response.json();
    }
    async function login(){
      const result=await api('login',{password:document.getElementById('password').value});
      if(result.ok){
        if(result.csrf) csrf=result.csrf;
        showAdmin();
        document.getElementById('password').value='';
      }
      else document.getElementById('loginMessage').textContent=result.message||'লগইন ব্যর্থ';
    }
    function showAdmin(){document.getElementById('loginBox').classList.add('hidden');document.getElementById('adminBox').classList.remove('hidden');loadProducts();}
    async function logout(){await api('logout');location.reload();}
    async function loadProducts(){
      const result=await api('products');
      if(!result.ok){location.reload();return;}
      document.getElementById('whatsapp').value=result.whatsapp||'';
      document.getElementById('list').innerHTML=(result.products||[]).map(p=>`<div class="item">${p.image?`<img src="${p.image}" alt="">`:'<div></div>'}<div><strong>${escapeHTML(p.name)}</strong><span>৳ ${escapeHTML(p.price)}</span></div><div class="actions"><button class="secondary" onclick="editProduct(${p.id})">Edit</button><button class="danger" onclick="deleteProduct(${p.id})">মুছুন</button></div></div>`).join('');
      window.productCache=result.products||[];
    }
    async function saveProduct(){
      const id=document.getElementById('editId').value;
      const name=document.getElementById('name').value.trim();
      const price=document.getElementById('price').value.trim();
      if(!name||!price){alert('নাম ও দাম লিখুন');return;}
      const file=document.getElementById('image').files[0];
      let image='';
      if(file){
        if(file.size>2000000){alert('ছবির সাইজ ২MB-এর কম দিন');return;}
        const form=new FormData();form.append('action','upload');form.append('csrf',csrf);form.append('image',file);
        const uploaded=await api('upload',form,true);
        if(!uploaded.ok){alert(uploaded.message);return;}image=uploaded.path;
      }
      const result=await api(id?'update':'create',{id:id?Number(id):undefined,name,price,image});
      if(result.ok){resetForm();loadProducts();}
      else alert(result.message);
    }
    function editProduct(id){const p=window.productCache.find(x=>x.id===id);document.getElementById('editId').value=p.id;document.getElementById('name').value=p.name;document.getElementById('price').value=p.price;document.getElementById('formTitle').textContent='পণ্য পরিবর্তন';}
    function resetForm(){document.querySelectorAll('#editId,#name,#price').forEach(el=>el.value='');document.getElementById('image').value='';document.getElementById('formTitle').textContent='নতুন পণ্য';}
    async function deleteProduct(id){if(confirm('পণ্যটি মুছবেন?')){await api('delete',{id});loadProducts();}}
    async function saveSettings(){
      const result=await api('settings',{whatsapp:document.getElementById('whatsapp').value,newPassword:document.getElementById('newPassword').value});
      document.getElementById('settingsMessage').textContent=result.ok?'সেটিংস সংরক্ষিত হয়েছে':(result.message||'ব্যর্থ');
      if(result.ok)document.getElementById('newPassword').value='';
    }
    function escapeHTML(v){return String(v).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]))}
    document.getElementById('password').addEventListener('keydown',e=>{if(e.key==='Enter')login()});
  </script>
</body>
</html>
