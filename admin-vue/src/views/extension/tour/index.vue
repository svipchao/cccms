<template>
  <div class="ele-body ele-body-card">
    <a-card title="基本用法" :bordered="false">
      <div>
        <a-button type="primary" @click="onStart1">开始引导</a-button>
      </div>
      <div style="margin-top: 20px">
        <a-space size="large">
          <a-button ref="uploadRef1">Upload</a-button>
          <a-button ref="saveRef1" type="primary">Save</a-button>
          <a-button ref="moreRef1">More</a-button>
        </a-space>
      </div>
      <ele-tour v-model="current1" :steps="steps1" />
    </a-card>
    <a-card title="不带遮罩层" :bordered="false">
      <div>
        <a-button type="primary" @click="onStart2">开始引导</a-button>
      </div>
      <div style="margin-top: 20px">
        <a-space size="large">
          <a-button ref="uploadRef2">Upload</a-button>
          <a-button ref="saveRef2" type="primary">Save</a-button>
          <a-button ref="moreRef2">More</a-button>
        </a-space>
      </div>
      <ele-tour v-model="current2" :steps="steps2" :mask="false" />
    </a-card>
    <a-card title="混合弹窗等多种形式" :bordered="false">
      <div>
        <a-button type="primary" @click="onStart3">开始引导</a-button>
      </div>
      <div style="margin-top: 20px">
        <a-space size="large">
          <a-button ref="uploadRef3">Upload</a-button>
          <a-button ref="saveRef3" type="primary">Save</a-button>
          <a-button ref="moreRef3">More</a-button>
        </a-space>
      </div>
      <ele-tour v-model="current3" :steps="steps3">
        <template #text="{ step, current }">
          <template v-if="current === 0">
            <div style="margin-bottom: 10px">
              <img
                src="https://gw.alipayobjects.com/mdn/rms_08e378/afts/img/A*P0S-QIRUbsUAAAAAAAAAAABkARQnAQ"
                style="height: 184px; width: 100%; object-fit: cover"
              />
            </div>
            <div>{{ step.description }}</div>
          </template>
        </template>
      </ele-tour>
    </a-card>
  </div>
</template>

<script setup>
  import { ref } from 'vue';

  // 当前步骤
  const current1 = ref(null);

  // 按钮
  const uploadRef1 = ref(null);
  const saveRef1 = ref(null);
  const moreRef1 = ref(null);

  // 步骤
  const steps1 = ref([
    {
      target: () => uploadRef1.value?.$el,
      title: '如何进行文件上传',
      description: '点击这个按钮在弹出框中选择想要上传的文件即可.'
    },
    {
      target: () => saveRef1.value?.$el,
      title: '如何提交数据',
      description: '数据录入完成后点击这个按钮即可提交数据到后台.'
    },
    {
      target: () => moreRef1.value?.$el,
      title: '如何进行更多的操作',
      description: '鼠标移入到此按钮上即可展示出更多的操作功能.'
    }
  ]);

  /* 开始引导 */
  const onStart1 = () => {
    current1.value = 0;
  };

  // 当前步骤
  const current2 = ref(null);

  // 按钮
  const uploadRef2 = ref(null);
  const saveRef2 = ref(null);
  const moreRef2 = ref(null);

  // 步骤
  const steps2 = ref([
    {
      target: () => uploadRef2.value?.$el,
      title: '如何进行文件上传',
      description: '点击这个按钮在弹出框中选择想要上传的文件即可.',
      popoverProps: { placement: 'topLeft' }
    },
    {
      target: () => saveRef2.value?.$el,
      title: '如何提交数据',
      description: '数据录入完成后点击这个按钮即可提交数据到后台.',
      popoverProps: { placement: 'bottom' }
    },
    {
      target: () => moreRef2.value?.$el,
      title: '如何进行更多的操作',
      description: '鼠标移入到此按钮上即可展示出更多的操作功能.',
      popoverProps: { placement: 'topRight' }
    }
  ]);

  /* 开始引导 */
  const onStart2 = () => {
    current2.value = 0;
  };

  // 当前步骤
  const current3 = ref(null);

  // 按钮
  const uploadRef3 = ref(null);
  const saveRef3 = ref(null);
  const moreRef3 = ref(null);

  // 步骤
  const steps3 = ref([
    {
      title: '欢迎使用 EleAdminPro 系统',
      description:
        '下面将为您介绍一些常用功能的操作说明, 如果之前已经为您介绍过, 您可以直接点击跳过结束指引.'
    },
    {
      target: () => uploadRef3.value?.$el,
      title: '如何进行文件上传',
      description: '点击这个按钮在弹出框中选择想要上传的文件即可.'
    },
    {
      target: () => saveRef3.value?.$el,
      title: '如何提交数据',
      description: '数据录入完成后点击这个按钮即可提交数据到后台.',
      mask: false
    },
    {
      target: () => moreRef3.value?.$el,
      title: '如何进行更多的操作',
      description: '鼠标移入到此按钮上即可展示出更多的操作功能.'
    }
  ]);

  /* 开始引导 */
  const onStart3 = () => {
    current3.value = 0;
  };
</script>

<script>
  export default {
    name: 'ExtensionTour'
  };
</script>
