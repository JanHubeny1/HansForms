<template>
    <div>
        <div v-if="!this.loading && !this.errored" class="container-fluid">
            <div class="row m-1">
                <div class="col shadow-sm bg-white pt-3">
                    <h1>{{ this.form.name }}</h1>
                    <h3>{{ this.form.description }}</h3>

                    <hr/>
                    <h5>Opened from {{ this.form.start_time }}</h5>
                    <h5>Closing at {{ this.form.end_time }}</h5>

                    <h4 v-if="this.form.is_expired">Expired!</h4>
                    <h4 v-else-if="this.form.is_opened">Opened to fill in.</h4>
                    <h4 v-else>Waiting for publication.</h4>

                    <hr/>
                    <p v-if="this.hasPublicLink" class="font-weight-bold">
                        Public link<br/>
                        <router-link
                            :to="/form/ + getSlug()"
                            class="font-weight-normal"
                        >{{ this.publicLink }}
                        </router-link>
                    </p>
                    <div v-else>
                        <div class="font-weight-bold">Private form</div>
                        <div
                            v-if="
                                this.form.form_private_access_tokens.length ===
                                0
                            "
                        >
                            No one has been invited yet.
                        </div>
                        <div v-else>
                            <div>
                                Invited people
                            </div>
                            <div
                                style="max-height: 100px"
                                class="border mb-3 overflow-auto"
                            >
                                <div
                                    class="text-left border-bottom pl-1"
                                    v-for="privateEmail in this.form
                                        .form_private_access_tokens"
                                    :key="privateEmail.id"
                                >
                                    {{ privateEmail.email }}<br/>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md justify-content-center">
                        <FormulateInput
                            class="btn"
                            @click="handleDuplicate"
                            label="Duplicate"
                            type="button"
                        />
                        <FormulateInput
                            class="btn"
                            @click="handleDelete"
                            label="Delete"
                            type="button"
                        />
                        <FormulateInput
                            v-if="!this.form.was_already_published"
                            class="btn"
                            @click="handleUpdate"
                            label="Edit"
                            type="button"
                        />
                        <FormulateInput
                            v-if="!this.form.is_expired"
                            class="btn"
                            @click="handleAccessibility"
                            label="Accessibility..."
                            type="button"
                        />
                        <FormulateInput
                            v-if="this.form.was_already_published"
                            class="btn"
                            @click="handleResults"
                            label="Results"
                            type="button"
                        />
                    </div>
                    <br/>
                </div>
                <div class="col shadow-sm bg-white pt-3">
                    <h1>Interactive preview</h1>
                    <p>This is how it is shown to users.</p>
                    <div>
                        <FormulateForm
                            v-model="formValues"
                            style="max-height: 75vh"
                            class="overflow-auto"
                        >
                            <form-element
                                v-for="item in this.form.form_elements"
                                :obj="item"
                                :key="item.order"
                            />
                        </FormulateForm>
                    </div>
                </div>
            </div>
        </div>
        <div v-if="this.loading">
            <loading/>
        </div>
        <div v-if="this.errored">
            <h1>{{ this.errorText }}</h1>
            <router-link to="/"><h3>Go home</h3></router-link>
        </div>
    </div>
</template>

<script>
import Form from "../apis/Form";
import FormElement from "../components/FormElement";
import FormDuplicationModal from "../components/Modals/FormReview/FormDuplicationModal";
import {duplicateFormStore} from "../store";
import FormAccessibilityModal from "../components/Modals/FormReview/FormAccessibilityModal";

export default {
    name: "FormPreview",
    components: {
        "form-element": FormElement,
    },
    data() {
        return {
            slug: "",
            form: {},
            formValues: {},
            loading: true,
            errored: false,
            errorText: "Bad Request",
            dataFetched: false,
            publicLink: "#",
            hasPublicLink: true,
        };
    },
    async mounted() {
        this.slug = this.getSlug();
        await this.getThisForm().then(() => {
            try {
                if (this.dataFetched) {
                    if (this.form.has_private_token) this.hasPublicLink = false;
                    if (this.hasPublicLink)
                        this.publicLink = `${window.location}`.replace(
                            "/preview",
                            ""
                        );
                    this.sortElements();
                    this.loading = false;
                }
            } catch (error) {
                this.errored = true;
                this.errorText = `Unhandled error - ${error}`;
            } finally {
                this.loading = false;
            }
        });
    },
    methods: {
        async getThisForm() {
            let errorCode = -1;
            await Form.getSpecificFormWithAuth(this.slug)
                .then((res) => {
                    if (res.status === 200) {
                        this.form = res.data;
                        this.dataFetched = true;
                    } else {
                        console.log(res.status);
                        errorCode = res.status;
                        throw new Error();
                    }
                })
                .catch((error) => {
                    this.errored = true;
                    switch (errorCode) {
                        case 401:
                            this.errorText =
                                "Requested form doesn't belong to your account!";
                            break;
                        case 404:
                            this.errorText = "Requested form was not found.";
                            break;
                        default:
                            this.errorText = `Unhandled error - ${error}`;
                            break; //dev only
                    }
                    this.dataFetched = false;
                });
        },
        getSlug() {
            return this.$route.params["slug"] ?? "";
        },
        sortElements() {
            this.form.form_elements.sort((a, b) => {
                if (a.order < b.order) {
                    return -1;
                }
                if (a.order > b.order) {
                    return 1;
                }
                return 0;
            });
        },
        async handleDelete() {
            if (confirm("Are you sure that you want to delete this form?")) {
                this.loading = true;
                await Form.deleteForm(this.getSlug()).then((res) => {
                    if (res.status === 200) {
                        this.$router.push("/");
                        this.loading = false;
                        this.$toasted.success(`Form was deleted successfully.`);
                    } else {
                        this.$toasted.error(
                            `Form deletion was not successful. Try it again.`
                        );
                    }
                });
            }
        },
        async handleDuplicateModalData() {
            if (!duplicateFormStore.isStoreEmpty()) {
                this.loading = true;
                await Form.postDuplicateForm({
                    slug: this.getSlug(),
                    ...duplicateFormStore.getData(),
                })
                    .then((res) => {
                        if (res.status === 200) {
                            this.$toasted.success(
                                "Form duplication was successful."
                            );
                            if (res.headers.duplicatedformslug) {
                                window.location =
                                    `${window.location}`.replace(
                                        new RegExp("/preview.*"),
                                        ""
                                    ) +
                                    "/preview/" +
                                    res.headers.duplicatedformslug;
                            } else {
                                this.$router.push("/");
                            }
                            //not used because of redirection
                            //this.loading = false;
                        } else throw new Error(res.data.toString());
                    })
                    .catch((error) => {
                        this.$toasted.error(
                            `Form duplication was not successful. (${error})`
                        );
                        this.loading = false;
                    });
            }
            duplicateFormStore.clearData();
        },
        handleDuplicate() {
            this.$modal.show(
                FormDuplicationModal,
                {
                    obj: {
                        name: this.form.name,
                        description: this.form.description,
                        start_time: this.form.start_time,
                        end_time: this.form.end_time,
                        has_private_token: this.form.has_private_token,
                        private_emails: this.form.form_private_access_tokens,
                    },
                },
                {height: "auto", width: "60%", scrollable: true},
                {"before-close": (event) => this.handleDuplicateModalData()}
            );
        },
        handleUpdate() {
            this.$router.push("/update_form/" + this.getSlug());
        },
        handleResults() {
            this.$router.push("/form/results/" + this.getSlug());
        },
        handleAccessibility() {
            this.$modal.show(
                FormAccessibilityModal,
                {
                    has_private_token: this.form.has_private_token,
                    emails: this.form.form_private_access_tokens,
                    slug: this.getSlug(),
                },
                {height: "auto", width: "60%", scrollable: true},
                {
                    /*'before-close': event => this.handleItemsChanged()*/
                }
            );
        },
    },
};
</script>

<style scoped></style>
