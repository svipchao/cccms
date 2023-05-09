<template>
  <div class="cccms-mark"></div>
</template>

<style scoped lang="less">
.cccms-mark {
  top: 0;
  left: 0;
  width: 100vw;
  z-index: 998;
  position: fixed;
  height: 100vh;
  background-color: rgba(0, 0, 0, 0.15);
  @media screen and (min-width: 930px) {
    display: none;
  }
}
</style>
