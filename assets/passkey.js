(function(){
  function b64u(buf){ var b = new Uint8Array(buf), s = ''; b.forEach(function(x){ s += String.fromCharCode(x); }); return btoa(s).replace(/\+/g,'-').replace(/\//g,'_').replace(/=+$/,''); }
  function unb64u(s){ s = s.replace(/-/g,'+').replace(/_/g,'/'); while (s.length % 4) s += '='; var bin = atob(s), a = new Uint8Array(bin.length); for (var i = 0; i < bin.length; i++) a[i] = bin.charCodeAt(i); return a.buffer; }
  function post(a, data){ return fetch('passkey.php?a=' + a, {method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':window.GP_CSRF||''}, body:JSON.stringify(data||{}), credentials:'same-origin'}).then(function(r){ return r.json(); }); }
  window.GPPasskey = {
    supported: function(){ return !!(window.PublicKeyCredential && navigator.credentials && window.isSecureContext); },
    register: function(name){
      return post('reg_options').then(function(o){
        if (!o.ok) throw new Error(o.error); var pk = o.options; pk.challenge = unb64u(pk.challenge); pk.user.id = unb64u(pk.user.id);
        (pk.excludeCredentials || []).forEach(function(c){ c.id = unb64u(c.id); });
        return navigator.credentials.create({publicKey: pk});
      }).then(function(cred){
        var r = cred.response;
        return post('reg_verify', {id:cred.id, name:name, transports:(r.getTransports ? r.getTransports() : []), response:{clientDataJSON:b64u(r.clientDataJSON), attestationObject:b64u(r.attestationObject)}});
      });
    },
    ac: null,
    // Offer saved passkeys in the username box; picking one signs the person in.
    autofill: function(){
      var self = this;
      if (!self.supported() || !window.PublicKeyCredential.isConditionalMediationAvailable) return Promise.resolve({ok:false, skipped:true});
      return window.PublicKeyCredential.isConditionalMediationAvailable().then(function(yes){
        if (!yes) return {ok:false, skipped:true};
        return self.login(true);
      });
    },
    login: function(auto){
      var self = this;
      if (self.ac) { try { self.ac.abort(); } catch (e) {} self.ac = null; }
      return post('login_options', {auto: !!auto}).then(function(o){
        if (!o.ok) throw new Error(o.error); var pk = o.options; pk.challenge = unb64u(pk.challenge);
        var opt = {publicKey: pk};
        if (auto) { self.ac = new AbortController(); opt.mediation = 'conditional'; opt.signal = self.ac.signal; }
        return navigator.credentials.get(opt);
      }).then(function(cred){
        var r = cred.response;
        return post('login_verify', {id:cred.id, response:{clientDataJSON:b64u(r.clientDataJSON), authenticatorData:b64u(r.authenticatorData), signature:b64u(r.signature), userHandle:r.userHandle ? b64u(r.userHandle) : ''}});
      });
    }
  };
})();
