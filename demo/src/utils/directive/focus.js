const focus = {
  mounted: (el, binding) => {
    if (binding.value === true || binding.value === undefined) {
      el.querySelector('input').focus();
    }
  },
};
export default focus;
