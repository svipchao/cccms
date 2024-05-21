<template>
  <div class="ele-body ele-body-card">
    <a-card title="基本用法" :bordered="false">
      <ele-dashboard type="success" style="margin-right: 18px">
        <div style="line-height: 1">
          <span style="font-size: 48px">100</span>
          <span style="font-size: 12px; margin-left: 4px">分</span>
        </div>
        <div style="margin-top: 4px">安全</div>
      </ele-dashboard>
      <ele-dashboard type="warning" style="margin-right: 18px">
        <div style="line-height: 1">
          <span style="font-size: 48px">70</span>
          <span style="font-size: 12px; margin-left: 4px">分</span>
        </div>
        <div style="margin-top: 4px">待优化</div>
      </ele-dashboard>
      <ele-dashboard type="danger">
        <div style="line-height: 1">
          <span style="font-size: 48px">40</span>
          <span style="font-size: 12px; margin-left: 4px">分</span>
        </div>
        <div style="margin-top: 4px">高风险</div>
      </ele-dashboard>
    </a-card>
    <a-card title="自定义颜色和尺寸" :bordered="false">
      <ele-dashboard color="#722ED1" style="margin-right: 18px">
        <div style="font-size: 48px">100</div>
      </ele-dashboard>
      <ele-dashboard size="116px" space="12px">
        <div style="font-size: 38px">100</div>
      </ele-dashboard>
    </a-card>
  </div>
</template>

<script>
  export default {
    name: 'ExtensionDashboard'
  };
</script>
