const r=[{en:"username",zh:"用户名"},{en:"user_id",zh:"用户ID"},{en:"time",zh:"时间"},{en:"newline",zh:"换行"}];function a(n,e){return`{${e==="zh-CN"?n.zh:n.en}}`}function i(n,e){return(n||"").replace(/\\n/g,`
`).replace(/\{(username|用户名)\}/gi,e.username).replace(/\{(user_?id|用户id)\}/gi,e.userId).replace(/\{(time|时间|日期)\}/gi,e.time).replace(/\{(newline|br|换行)\}/gi,`
`)}export{r as W,i as r,a as w};
