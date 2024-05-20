import persistedstate from 'pinia-plugin-persistedstate';
import { createPersistedState } from 'pinia-plugin-persistedstate';

const pinia = createPinia();

// console.log(persistedstate({
//   key: (id) => `cccms_${id}`,
//   storage: localStorage,
// }));
pinia.use(persistedstate);
// pinia.use(
//   createPersistedState({
//     key: (id) => `cccms_${id}`,
//     storage: localStorage,
//   })
// );

export default pinia;


// const pinia = createPinia();
// pinia.use(
//   createPersistedState({
//     key: (id) => `cccms_${id}`,
//     storage: localStorage,
//   })
// );
// app.use(pinia);