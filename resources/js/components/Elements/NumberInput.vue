<template>
    <div>
        <FormulateInput
            :name="this.propsId('number')"
            type="number"
            :step="this.step"
            :label="propsLabel"
            :validation="validationRules"
            validationName="Number input"
            validation-behavior="live"
        />
        <FormulateErrors/>
    </div>
</template>

<script>

import {FormElementDefaultComputedProps, FormElementDefaultMethods, FormElementDefaultProps} from "./defaults";

export default {
    name: "NumberInput",
    data() {
        return {
            step: "any"
        }
    },
    props: [...FormElementDefaultProps],
    computed: {...FormElementDefaultComputedProps,
        validationRules() {
            let validation = [];

            if (this.propsIsMandatory) validation.push(['required']);
            if (this.propsObj.min) validation.push(['min', this.propsObj.min]);
            if (this.propsObj.max) validation.push(['max', this.propsObj.max]);
            if (this.propsObj.can_be_decimal) {
                validation.push(['matches', '/(^(\\+|-)?[0-9]+[,|.][0-9]+)|(^(\\+|-)?[0-9]*$)/']);
                this.step = "any";
            }
            else {
                validation.push(['matches', '/^(\\+|-)?[0-9]*$/']);
                this.step = "0.0";
            }

            return validation;
        }},
    methods: {...FormElementDefaultMethods}
}
</script>

<style scoped>

</style>
