<template>
  <a-form :model="props.data" layout="vertical">
    <a-form-item v-for="(data, index) in props.data" :label="data.label">
      <a-input
        v-if="data.type === 'input'"
        v-model="data['value']"
        :placeholder="data.placeholder"
      />
      <a-input-number
        v-else-if="data.type === 'input-number'"
        v-model="data['value']"
        :placeholder="data.placeholder"
      />
      <a-textarea
        v-else-if="data.type === 'textarea'"
        v-model="data['value']"
        :placeholder="data.placeholder"
        allow-clear
      />
      <a-select
        v-else-if="data.type === 'select'"
        v-model="data['value']"
        :placeholder="data.placeholder"
      >
        <a-option
          v-for="(d, i) in data.options"
          :value="d.value"
          :label="d.label"
        />
      </a-select>
      <a-select
        v-else-if="data.type === 'multiple-select'"
        v-model="data['value']"
        :placeholder="data.placeholder"
        multiple
      >
        <a-option
          v-for="(d, i) in data.options"
          :value="d.value"
          :label="d.label"
        />
      </a-select>
      <a-date-picker
        v-else-if="data.type === 'date-picker'"
        v-model="data['value']"
      />
      <a-range-picker
        showTime
        v-else-if="data.type === 'date-range-picker'"
        v-model="data['value']"
      />
      <a-switch
        v-else-if="data.type === 'switch'"
        v-model="data['value']"
        :checked-value="data.options['checked']"
        :unchecked-value="data.options['unchecked']"
      />
      <template #extra>
        <div>{{ data.description }}</div>
      </template>
    </a-form-item>
  </a-form>
</template>

<script setup>
const props = defineProps({
  data: {},
});

const getDynamicDatas = () => {
  return props.data;
};

defineExpose({
  getDynamicDatas,
});
</script>
