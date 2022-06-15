<template>
  <div id="vditor" />
</template>

<script setup>
import { ref, onMounted } from "vue";
import { assignObject } from "@/utils/utils.js";
import Vditor from "vditor";
import "vditor/dist/index.css";

const props = defineProps({
  options: {},
});

const vditor = ref(null);

onMounted(() => {
  vditor.value = new Vditor(
    "vditor",
    assignObject(
      {
        cache: {
          enable: false,
        },
        mode: "wysiwyg",
        placeholder: "内容",
        cdn: "vditor",
        width: "100%",
        height: 200,
        toolbar: [
          "headings",
          "bold",
          "italic",
          "strike",
          "link",
          "|",
          "list",
          "ordered-list",
          "check",
          "outdent",
          "indent",
          "|",
          "quote",
          "line",
          "code",
          "inline-code",
          "insert-before",
          "insert-after",
          "|",
          "upload",
          "table",
          "|",
          "fullscreen",
        ],
      },
      props.options
    )
  );
});

defineExpose({ vditor });
</script>
