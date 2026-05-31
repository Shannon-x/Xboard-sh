# Xboard-sh

> 🛠️ 个人魔改版后端面板 · Fork of [cedar2025/Xboard](https://github.com/cedar2025/Xboard)

<div align="center">

![PHP](https://img.shields.io/badge/PHP-8.2+-green.svg)
![MySQL](https://img.shields.io/badge/MySQL-5.7+-blue.svg)
[![License](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)

</div>

---

## ⚠️ 重要说明（请先看完再决定是否使用）

**本仓库是个人基于 Xboard 的深度魔改版本，已经不是原版 Xboard。** 在使用前你必须知道以下事实：

1. **代码逻辑与上游差异巨大**
   控制器、模型、迁移、路由、定时任务、缓存策略、配置项默认值等多处都做了非兼容修改。**不要**期望它能与原版 Xboard 的文档/插件/迁移脚本无缝对应。

2. **节点端支持 [v2node](https://github.com/wyx2685/V2bX/tree/v2node) 与 [V2bX](https://github.com/wyx2685/V2bX) 主线，完全不支持原版 Xboard 的 xbnode 协议**
   面板内的协议字段、节点对接 API、推送格式、在线汇报、用户流量上报全部按 v2node / V2bX 的接口契约对接（**全面适配 v2node**，V2bX 主线一并兼容）。如果你用的是 xbnode、[XrayR-Project/XrayR](https://github.com/XrayR-project/XrayR) 等其他节点端，**接不上就是接不上**，请不要在 Issue 里问"为什么连不上"。

3. **配套前端是另一个魔改项目**
   推荐搭配 [XBoard-admin](https://github.com/Shannon-x/XBoard-admin)（个人重写的 Vue3 + Element Plus 后台），原版 Xboard 的 React 后台与本仓库部分接口不兼容，可能出现字段缺失/接口 404/行为不一致。

4. **不接受"与原版行为不一致"的 Bug 报告**
   行为不一致就是本项目的设计目标，不是 Bug。

5. **没有维护承诺**
   纯个人按需修改，可能随时引入 Breaking Change。生产环境使用请自行做好版本锁定和数据库备份。

6. **本项目仅供学习交流**
   一切因使用本项目造成的后果（违法、被封、被攻击、数据丢失等）由使用者自行承担，与作者无关。

---

## 🎯 与原版 Xboard 的主要差异

| 维度 | 原版 Xboard | 本仓库 (Xboard-sh) |
| --- | --- | --- |
| 后端节点端 | 支持 xbnode / V2bX / XrayR 等多种 | **全面适配 [v2node](https://github.com/wyx2685/V2bX/tree/v2node)，同时兼容 [V2bX](https://github.com/wyx2685/V2bX) 主线** |
| xbnode 协议兼容性 | 原生支持 | **完全不支持，已移除/改写相关字段** |
| 节点协议字段 | 上游字段 | 按 v2node / V2bX 重新定义（routes / 标签 / 优先级等） |
| 配套后台 | 内置 React 后台 | 推荐使用 [XBoard-admin](https://github.com/Shannon-x/XBoard-admin) |
| 公告 / 节点 / 套餐 / 路由 | 上游字段 | 多处增删字段、改默认值、加新表迁移 |
| 定时任务与缓存 | 上游策略 | 按自身需求调整 |

如果你的目标是"标准 Xboard 体验"，**请去用上游 [cedar2025/Xboard](https://github.com/cedar2025/Xboard)，不要用这个仓库**。

---

## 🚀 快速开始

> 注意：上游的 docker compose 一键脚本不保证在本仓库可用，建议手动部署。

```bash
git clone https://github.com/Shannon-x/Xboard-sh.git
cd Xboard-sh

# 安装依赖
composer install --no-dev --optimize-autoloader

# 配置 .env
cp .env.example .env
php artisan key:generate

# 数据库迁移 + 安装
php artisan xboard:install

# 启动 Octane
php artisan octane:start --host=0.0.0.0 --port=7001
```

节点端必须用 v2node 或 V2bX：

```bash
# v2node 仓库（推荐，全面适配）
https://github.com/wyx2685/V2bX/tree/v2node

# V2bX 主线（也兼容）
https://github.com/wyx2685/V2bX
```

> 不支持 xbnode、XrayR 等其他节点端。

---

## 🛠️ 技术栈

- **后端**：Laravel 11 + Octane
- **节点端**：全面适配 **v2node**，同时兼容 **V2bX 主线**；**不支持 xbnode**
- **数据库**：MySQL 5.7+ / SQLite
- **缓存**：Redis / Octane Cache
- **部署**：可选 Docker、aaPanel、1Panel（需自行调整脚本）

---

## 📦 配套生态

- 后端：**本仓库** ([Shannon-x/Xboard-sh](https://github.com/Shannon-x/Xboard-sh))
- 后台前端：[Shannon-x/XBoard-admin](https://github.com/Shannon-x/XBoard-admin)（推荐）
- 节点端：[V2bX v2node 分支](https://github.com/wyx2685/V2bX/tree/v2node)（推荐） / [V2bX 主线](https://github.com/wyx2685/V2bX)

三件套绑定使用，混搭其他节点/后台后果自负。

---

## 📖 文档

- 上游开发文档（部分仍可参考）：[docs/](./docs/)
- 插件开发（行为可能与上游不同）：[Plugin Development Guide](./docs/en/development/plugin-development-guide.md)
- 部署相关文档在 [docs/en/installation/](./docs/en/installation/)，但**请视为参考，不保证对本仓库 100% 适用**

---

## ⚠️ 免责声明

1. 本项目为 [cedar2025/Xboard](https://github.com/cedar2025/Xboard) 的个人魔改 Fork，**与上游官方无关**，不代表上游立场。
2. 本项目仅供个人学习、技术研究交流。
3. **严禁**用于任何违反所在国家或地区法律法规的用途。
4. 一切因使用本项目产生的法律责任、数据安全问题、服务中断、资产损失等，**均由使用者自行承担**，作者不承担任何责任。
5. 使用即视为已阅读并同意本声明。

---

## 🌟 致谢

- [cedar2025/Xboard](https://github.com/cedar2025/Xboard) — 上游项目
- [v2board/v2board](https://github.com/v2board/v2board) — 项目起源
- [wyx2685/V2bX (v2node 分支)](https://github.com/wyx2685/V2bX/tree/v2node) — 主要适配的节点端
- [wyx2685/V2bX (主线)](https://github.com/wyx2685/V2bX) — 同时兼容

---

## 📈 Star History

[![Stargazers over time](https://starchart.cc/Shannon-x/Xboard-sh.svg)](https://starchart.cc/Shannon-x/Xboard-sh)
