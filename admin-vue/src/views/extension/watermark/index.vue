<template>
  <div class="ele-body ele-body-card">
    <a-card title="基本用法" :bordered="false">
      <ele-watermark content="Ele Admin Pro" :gap="[60, 40]">
        <div style="height: 200px"></div>
      </ele-watermark>
    </a-card>
    <a-card title="多行水印" :bordered="false">
      <ele-watermark
        :content="['Ele Admin Pro', 'Happy Working']"
        :gap="[60, 40]"
      >
        <div style="height: 200px"></div>
      </ele-watermark>
    </a-card>
    <a-card title="图片水印" :bordered="false">
      <ele-watermark
        :height="30"
        :width="130"
        image="https://mdn.alipayobjects.com/huamei_7uahnr/afts/img/A*lkAoRbywo0oAAAAAAAAAAAAADrJ8AQ/original"
        :gap="[60, 40]"
      >
        <div style="height: 200px"></div>
      </ele-watermark>
    </a-card>
  </div>
</template>

<script>
  export default {
    name: 'ExtensionWatermark'
  };
</script>
