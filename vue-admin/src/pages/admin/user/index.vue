<template>
  <a-table
    stripe
    bordered
    hoverable
    column-resizable
    hide-expand-button-on-empty
    :scroll="{ x: 600 }"
    :columns="columns"
    :data="data"
  />
  <a-row>
    <a-col flex="100px">
      <div>100px</div>
    </a-col>
    <a-col flex="auto">
      <div style="width: 100%">
        <a-table
          stripe
          bordered
          hoverable
          column-resizable
          hide-expand-button-on-empty
          :scroll="{ x: 600 }"
          :columns="columns"
          :data="data"
        />
      </div>
    </a-col>
  </a-row>
</template>

<script setup>
import { reactive } from "vue";
const columns = [
  {
    title: "Name",
    dataIndex: "name",
    width: 150,
  },
  {
    title: "Salary",
    dataIndex: "salary",
    width: 150,
  },
  {
    title: "Address",
    dataIndex: "address",
    width: 150,
  },
  {
    title: "Email",
    dataIndex: "email",
    width: 150,
  },
];
const data = reactive([
  {
    key: "1",
    name: "Jane Doe",
    salary: 23000,
    address: "32 Park Road, London",
    email: "jane.doe@example.com",
  },
  {
    key: "2",
    name: "Alisa Ross",
    salary: 25000,
    address: "35 Park Road, London",
    email: "alisa.ross@example.com",
  },
  {
    key: "3",
    name: "Kevin Sandra",
    salary: 22000,
    address: "31 Park Road, London",
    email: "kevin.sandra@example.com",
  },
  {
    key: "4",
    name: "Ed Hellen",
    salary: 17000,
    address: "42 Park Road, London",
    email: "ed.hellen@example.com",
  },
  {
    key: "5",
    name: "William Smith",
    salary: 27000,
    address: "62 Park Road, London",
    email: "william.smith@example.com",
  },
]);
</script>
