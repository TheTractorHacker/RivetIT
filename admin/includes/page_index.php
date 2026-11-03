<?php
// "On this page" jump bar for long settings pages. Include just before the footer:
//   $page_index_selector = '.card-title, h5.mb-3'; require __DIR__ . '/includes/page_index.php';
// Builds a sticky row of anchor pills from the matched headings (needs 3+). Pure progressive
// enhancement: with no JS the page is unchanged. Labels use textContent only.
$page_index_selector = $page_index_selector ?? '.card-title';
$page_index_nonce = htmlspecialchars($csp_nonce ?? '', ENT_QUOTES);
?>
<?php require __DIR__ . '/jump_bar_css.php'; ?>
<script nonce="<?= $page_index_nonce ?>">
(function(){
  var sel=<?= json_encode($page_index_selector) ?>;
  var heads=[].slice.call(document.querySelectorAll(sel)).filter(function(h){return h.offsetParent!==null && h.textContent.trim().length;});
  if(heads.length<3)return;
  var nav=document.createElement('nav');nav.className='page-index';nav.setAttribute('aria-label','On this page');
  var lab=document.createElement('span');lab.className='page-index-label';lab.textContent='On this page';nav.appendChild(lab);
  var links=[];
  heads.forEach(function(h,i){
    var target=h.classList.contains('card-title')?(h.closest('.card')||h):h;
    var id=target.id||('pi-sec-'+i);target.id=id;target.style.scrollMarginTop='4.5rem';
    var a=document.createElement('a');a.href='#'+id;
    var t=h.cloneNode(true);[].forEach.call(t.querySelectorAll('small,i,.badge'),function(n){n.remove();});
    a.textContent=t.textContent.replace(/\s+/g,' ').trim();links.push([a,target]);nav.appendChild(a);
  });
  var first=heads[0].classList.contains('card-title')?(heads[0].closest('.card')||heads[0]):(heads[0].closest('.card')||heads[0]);var nb=document.querySelector('header.navbar');var st=getComputedStyle(nb||document.body).position;nav.style.top=(nb&&(st==='fixed'||st==='sticky'))?Math.round(nb.getBoundingClientRect().height)+'px':'0';first.parentNode.insertBefore(nav,first);
  var tick=false;function mark(){tick=false;var cur=links[0];links.forEach(function(l){if(l[1].getBoundingClientRect().top<window.innerHeight*0.3)cur=l;});links.forEach(function(l){l[0].classList.toggle('active',l===cur);});if(cur[0].scrollIntoView&&nav.scrollWidth>nav.clientWidth){var r=cur[0].offsetLeft-nav.clientWidth/3;nav.scrollLeft=r;}}
  window.addEventListener('scroll',function(){if(!tick){tick=true;requestAnimationFrame(mark);}},{passive:true});mark();
})();
</script>
